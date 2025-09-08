<div class="payment-header">
    <!-- ============================
         Estrutura de Linha
         ============================ -->
    <div class="row">
        <!-- ----------------------------
             Informação da Fatura
             ---------------------------- -->
        <div class="col-md-6">
            <h4 class="no-margin">
                <?php echo _l('invoice'); ?> - <?php echo format_invoice_number($invoice->id); ?>
            </h4>
        </div>

        <!-- ----------------------------
             Total da Fatura
             ---------------------------- -->
        <div class="col-md-6 text-right">
            <h4 class="text-success no-margin">
                Total: <?php echo app_format_money($invoice->total, $currency_name); ?>
            </h4>
        </div>
    </div>
</div>
