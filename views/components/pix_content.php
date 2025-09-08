<div class="pix-section">
    <?php if (!empty($qr_code_img)): ?>
    
    <!-- ============================
         Contêiner de QR Code PIX
       ============================ -->
    <div class="qr-code-container">
        <h4>Pagamento via PIX</h4>
        <p>Escaneie o QR Code abaixo com o aplicativo do seu banco</p>
        <img src="data:image/png;base64,<?php echo $qr_code_img; ?>" 
             alt="QR Code PIX" 
             class="qr-code-image">
    </div>
    
    <?php if (!empty($qr_code_text)): ?>
    
    <!-- ============================
         Seção de Cópia do Código PIX
       ============================ -->
    <div class="copy-section">
        <h5>Codigo PIX Copia e Cola</h5>
        <input type="text" class="copy-input" value="<?php echo $qr_code_text; ?>" readonly>
        <button class="btn btn-info btn-block" onclick="copyToClipboard('pix')">
            <i class="fa fa-copy"></i> Copiar Codigo PIX
        </button>
    </div>
    
    <?php endif; ?>
    <?php endif; ?>
</div>
