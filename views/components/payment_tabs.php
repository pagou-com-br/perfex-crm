<div class="payment-tabs">
    <!-- ============================
         Aba de Pagamento via Boleto
       ============================ -->
    <div class="payment-tab <?php echo $active_tab === 'boleto' ? 'active' : ''; ?>" 
         data-target="boleto-section">
        <i class="fa fa-barcode"></i> Pagou no Boleto
    </div>

    <!-- ============================
         Aba de Pagamento via PIX
       ============================ -->
    <div class="payment-tab <?php echo $active_tab === 'pix' ? 'active' : ''; ?>" 
         data-target="pix-section">
        <i class="fa fa-qrcode"></i> Pagou no PIX
    </div>
</div>
