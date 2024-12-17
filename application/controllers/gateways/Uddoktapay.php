<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Uddoktapay extends App_Controller
{
    /**
     * Show message to the customer whether the payment is successfully
     *
     * @return mixed
     */
    public function verify_payment()
    {
        $invoice_id = $this->input->get('invoice_id');

        $invoiceid = $this->input->get('invoiceid');
        $hash = $this->input->get('hash');
        check_invoice_restrictions($invoiceid, $hash);

        $this->db->where('id', $invoiceid);
        $invoice = $this->db->get(db_prefix() . 'invoices')->row();

        try {
            $response = $this->uddoktapay_gateway->fetch_payment($invoice_id);

            if ($response['status'] === 'COMPLETED') {
                // New payment
                $this->uddoktapay_gateway->addPayment([
                    'amount'        => $response['metadata']['amount'],
                    'invoiceid'     => $invoice->id,
                    'paymentmethod' => $response['payment_method'],
                    'transactionid' => $response['transaction_id'],
                ]);
                set_alert('success', _l('online_payment_recorded_success'));
            } else {
                set_alert('danger', 'Payment is pending for verification.');
            }
        } catch (\Exception $e) {
            set_alert('danger', $e->getMessage());
        }

        redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
    }

    /**
     * Handle the uddoktapay webhook
     *
     * @param  string $key
     *
     * @return mixed
     */
    public function webhook($key = null)
    {

        $response = $this->uddoktapay_gateway->fetch_payment();

        log_activity('UddoktaPay payment webhook called.');

        if (!$response) {
            log_activity('UddoktaPay payment not found via webhook.');

            return;
        }

        if ($response['metadata']['webhook_key'] !== $key) {
            log_activity('UddoktaPay payment webhook key does not match. Url Key: "' . $key . '", Metadata Key: "' . $response['metadata']['webhook_key'] . '"');

            return;
        }

        if ($response['status'] == 'COMPLETED') {
            $this->db->where('id', $response['metadata']['invoice_id']);
            $invoice = $this->db->get(db_prefix() . 'invoices')->row();
            // New payment
            $this->uddoktapay_gateway->addPayment([
                'amount'        => $response['metadata']['amount'],
                'invoiceid'     => $invoice->id,
                'paymentmethod' => $response['payment_method'],
                'transactionid' => $response['transaction_id'],
            ]);
        } else {
            log_activity('UddoktaPay payment failed. Status: ' . $response['status']);
        }
    }
}
