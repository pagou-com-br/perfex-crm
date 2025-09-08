<div class="boleto-section">
    <?php if (!empty($barcode)): ?>
    
    <!-- ============================
         Pré-visualização do Boleto
         ============================ -->
    <div class="boleto-preview">
        <h4 class="text-center">Visualizacao do Boleto</h4>
        <div class="embed-responsive embed-responsive-4by3">
            <a href="<?php echo $boleto_url; ?>" target="_blank">
                <img 
                    src="<?php echo base_url('modules/pagou/assets/public/6b15d14f-9b6e-4ea6-90c5-05598f7cb448.pdf.gif'); ?>" 
                    alt="Boleto" 
                    class="img-fluid embed-responsive-item" 
                    style="width: 100%; height: 100%; object-fit: contain; cursor: pointer;"
                >
            </a>
        </div>
    </div>
    
    <!-- ============================
         Seção de Cópia do Código de Barras
         ============================ -->
    <div class="copy-section">
        <h5>Codigo de Barras</h5>
        <input type="text" class="copy-input" value="<?php echo $barcode; ?>" readonly>
        <button class="btn btn-info btn-block" onclick="copyToClipboard('barcode')">
            <i class="fa fa-copy"></i> Copiar Codigo de Barras
        </button>
    </div>
    
    <?php endif; ?>

    <!-- ============================
         Botão para Visualizar o Boleto
         ============================ -->
    <div class="text-center">
        <a href="<?php echo $boleto_url; ?>" target="_blank" class="btn btn-primary btn-lg">
            <i class="fa fa-external-link"></i> Visualizar Boleto
        </a>
    </div>
</div>
