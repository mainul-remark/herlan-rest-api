<?php

namespace HerlanRestApi\Controllers;

use HerlanRestApi\Controller;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

if (! defined('ABSPATH')) {
    exit;
}

final class InvoiceController extends Controller
{
    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/orders/(?P<id>\d+)/invoice', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'download_invoice'],
            'permission_callback' => [$this, 'maybe_authenticate'],
            'args'                => [
                'id' => [
                    'required'          => true,
                    'validate_callback' => static fn ($v) => is_numeric($v) && absint($v) > 0,
                ],
                'order_key' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description'       => 'Required for guest orders instead of authentication.',
                ],
            ],
        ]);
    }

    /**
     * Download invoice for an order.
     * Authenticated users must own the order; guests must supply a matching order_key.
     */
    public function download_invoice(WP_REST_Request $request)
    {
        if (! function_exists('wc_get_order')) {
            return new WP_Error('herlan_woocommerce_unavailable', __('WooCommerce is not available.', 'herlan-rest-api'), ['status' => 500]);
        }

        $order_id = absint($request->get_param('id'));
        $order    = wc_get_order($order_id);

        if (! $order instanceof WC_Order) {
            return new WP_Error('herlan_order_not_found', __('Order not found.', 'herlan-rest-api'), ['status' => 404]);
        }

        $authorized = $this->authorize_order_access($order, $request);
        if (is_wp_error($authorized)) {
            return $authorized;
        }

        $allowed_statuses = apply_filters('herlan_invoice_allowed_statuses', ['processing', 'completed', 'on-hold']);
        if (! $order->has_status($allowed_statuses)) {
            return new WP_Error(
                'herlan_order_not_completed',
                __('Invoice is only available once the order is confirmed.', 'herlan-rest-api'),
                ['status' => 400]
            );
        }

        $pdf_path = $this->generate_invoice_pdf($order);

        if (is_wp_error($pdf_path)) {
            return $pdf_path;
        }

        $this->send_pdf_response($order, $pdf_path);
    }

    /**
     * Generate the invoice file using WooCommerce PDF Invoices & Packing Slips (wpo-wcpdf)
     * if it's active. Falls back to a simple HTML invoice otherwise.
     */
    private function generate_invoice_pdf(WC_Order $order): string|WP_Error
    {
        if (function_exists('wcpdf_get_invoice') && function_exists('wcpdf_get_document_file')) {
            try {
                $document = \wcpdf_get_invoice($order, true);

                if ($document && (! is_callable([$document, 'is_enabled']) || $document->is_enabled('pdf'))) {
                    $pdf_path = \wcpdf_get_document_file($document, 'pdf');

                    if ($pdf_path && file_exists($pdf_path)) {
                        return $pdf_path;
                    }
                }
            } catch (\Throwable $e) {
                // Fall through to the HTML fallback below.
            }
        }

        return $this->generate_html_invoice($order);
    }

    /**
     * Generate a simple HTML invoice used only when no PDF invoicing plugin is available.
     */
    private function generate_html_invoice(WC_Order $order): string
    {
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Invoice #' . esc_html($order->get_order_number()) . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; }
        .invoice-container { max-width: 800px; margin: 0 auto; }
        .header { text-align: center; margin-bottom: 30px; }
        .invoice-title { font-size: 24px; font-weight: bold; margin-bottom: 10px; }
        .invoice-number { font-size: 14px; color: #666; }
        .invoice-details { display: flex; justify-content: space-between; margin-bottom: 30px; }
        .section { margin-bottom: 20px; }
        .section-title { font-weight: bold; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #f5f5f5; }
        .totals { float: right; width: 200px; }
        .totals table { width: 100%; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; text-align: center; color: #666; }
    </style>
</head>
<body>
    <div class="invoice-container">
        <div class="header">
            <div class="invoice-title">INVOICE</div>
            <div class="invoice-number">Order #: ' . esc_html($order->get_order_number()) . '</div>
        </div>

        <div class="invoice-details">
            <div>
                <div class="section-title">Billing Details</div>
                <div>' . esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) . '</div>
                <div>' . esc_html($order->get_billing_address_1()) . '</div>';

        if ($order->get_billing_address_2()) {
            $html .= '<div>' . esc_html($order->get_billing_address_2()) . '</div>';
        }

        $html .= '<div>' . esc_html($order->get_billing_city()) . ', ' . esc_html($order->get_billing_state()) . ' ' . esc_html($order->get_billing_postcode()) . '</div>
                <div>' . esc_html($order->get_billing_country()) . '</div>
                <div>Email: ' . esc_html($order->get_billing_email()) . '</div>
                <div>Phone: ' . esc_html($order->get_billing_phone()) . '</div>
            </div>

            <div>
                <div class="section-title">Order Details</div>
                <div>Date: ' . esc_html($order->get_date_created()->format('Y-m-d')) . '</div>
                <div>Status: ' . esc_html($order->get_status()) . '</div>
                <div>Payment: ' . esc_html($order->get_payment_method_title()) . '</div>
            </div>
        </div>

        <div class="section">
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Price</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($order->get_items() as $item) {
            if (! $item instanceof \WC_Order_Item_Product) {
                continue;
            }

            $name     = $item->get_name();
            $quantity = $item->get_quantity();
            $total    = $item->get_total();

            $html .= '
                    <tr>
                        <td>' . esc_html($name) . '</td>
                        <td>' . esc_html($quantity) . '</td>
                        <td>' . wp_strip_all_tags(wc_price($total)) . '</td>
                    </tr>';
        }

        $html .= '
                </tbody>
            </table>
        </div>

        <div class="totals">
            <table>
                <tr>
                    <td>Subtotal:</td>
                    <td>' . wp_strip_all_tags(wc_price($order->get_subtotal())) . '</td>
                </tr>';

        if ($order->get_total_tax() > 0) {
            $html .= '<tr>
                    <td>Tax:</td>
                    <td>' . wp_strip_all_tags(wc_price($order->get_total_tax())) . '</td>
                </tr>';
        }

        if ($order->get_discount_total() > 0) {
            $html .= '<tr>
                    <td>Discount:</td>
                    <td>-' . wp_strip_all_tags(wc_price($order->get_discount_total())) . '</td>
                </tr>';
        }

        $html .= '
                <tr>
                    <td><strong>Total:</strong></td>
                    <td><strong>' . wp_strip_all_tags(wc_price($order->get_total())) . '</strong></td>
                </tr>
            </table>
        </div>

        <div class="footer">
            <p>Thank you for your order!</p>
            <p>Generated on: ' . esc_html(current_time('mysql')) . '</p>
        </div>
    </div>
</body>
</html>';

        $temp_file = wp_tempnam('herlan-invoice-' . $order->get_id());
        file_put_contents($temp_file, $html);

        return $temp_file;
    }

    /**
     * Stream the invoice file to the client and terminate the request.
     */
    private function send_pdf_response(WC_Order $order, string $file_path): never
    {
        $extension  = strtolower(pathinfo($file_path, PATHINFO_EXTENSION)) === 'html' ? 'html' : 'pdf';
        $content_type = $extension === 'pdf' ? 'application/pdf' : 'text/html';
        $order_number = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $order->get_order_number());
        $file_name  = 'invoice-order-' . $order_number . '.' . $extension;

        nocache_headers();
        header('Content-Type: ' . $content_type);
        header('Content-Disposition: attachment; filename="' . $file_name . '"');
        header('Content-Length: ' . filesize($file_path));

        readfile($file_path);
        exit;
    }
}
