<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<div style="background-color: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); max-width: 900px; margin: 50px auto;">

    <style>
    .payment-container {
        max-width: 900px;
        margin: 30px auto;
        padding: 20px;
        font-family: Arial, sans-serif;
    }

    .payment-title {
        text-align: center;
        font-size: 24px;
        font-weight: bold;
        margin-bottom: 20px;
    }

    .payment-tabs {
        display: flex;
        justify-content: center;
        margin-bottom: 30px;
        border-bottom: 1px solid #ddd;
    }

    .payment-tab {
        padding: 10px 20px;
        margin: 0 10px;
        cursor: pointer;
        border: 1px solid #ddd;
        border-bottom: none;
        border-radius: 5px 5px 0 0;
        background-color: #f8f9fa;
    }

    .payment-tab.active {
        background-color: #3d9aff;
        color: white;
        border-color: #3d9aff;
    }

    .payment-content {
        display: none;
    }

    .payment-content.active {
        display: block;
    }

    .payment-section {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        margin-bottom: 30px;
    }

    .pix-code {
        flex: 1;
        margin-right: 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .pix-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 30px;
    }
    .instruction-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .pix-instructions {
        flex: 1;
        margin-right: 20px;
        text-align: center;
    }

    .qr-code {
        flex: 1;
        text-align: center;
    }

    .barcode-section {
        text-align: center;
        margin: 30px 0;
    }

    .copy-section {
        text-align: center;
        margin-bottom: 20px;
    }

    .copy-input {
        width: 100%;
        padding: 10px;
        border: 1px solid #ced4da;
        border-radius: 4px;
        font-family: monospace;
        margin: 0 auto 10px auto;
        text-align: center;
    }

    .copy-button {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 10px;
        font-size: 14px;
        color: #ffffff;
        background-color: #3d9aff;
        border: 1px solid #ced4da;
        border-radius: 4px;
        cursor: pointer;
        text-decoration: none;
    }

    .copy-button i {
        font-size: 16px;
    }

    .copy-button:hover {
        background-color: #235a94;
    }

    .payment-status {
        background: #e9ecef;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        text-align: center;
    }

    .buttons-section {
        text-align: center;
        margin-top: 20px;
    }

    .qr-code img {
        max-width: 200px;
        margin: 0 auto;
    }

    .pix-instructions img {
        max-width: 100%;
        margin: 0 auto;
        border: 1px solid #ddd;
        border-radius: 4px;
    }
    
    .loading-indicator {
        text-align: center;
        padding: 20px;
    }
    
    .loading-indicator i {
        font-size: 24px;
        color: #3d9aff;
        animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    </style>

    <div class="payment-container">
        <?php
        $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
        $has_pix = !empty($qr_code_text);
        $is_paid = ((int) $invoice->status === 2);
        $invoice_url = site_url('invoice/' . (int) $invoice->id . '/' . $invoice->hash);
        ?>

        <div class="payment-tabs">
            <?php if ($has_pix): ?>
            <div class="payment-tab active" onclick="showPaymentTab('pix')">Pagamento por PIX</div>
            <?php endif; ?>
            <div class="payment-tab <?php echo !$has_pix ? 'active' : ''; ?>" onclick="showPaymentTab('boleto')">Pagamento por Boleto</div>
        </div>

        <?php if ($has_pix): ?>
        <div id="pix-content" class="payment-content active">
            <div class="payment-title">Pagamento por PIX</div>

            <div class="pix-row">
                <div class="pix-code" style="position: relative; width: 100%;">
                    <input type="text" id="pix-input" class="copy-input" value="<?php echo $e($qr_code_text); ?>" readonly style="width: 100%; padding-right: 80px;">
                    <button class="copy-button" onclick="copyToClipboard('pix-input', this)" style="position: absolute; right: 0; top: 0; border-left: none;">
                        <i class="fa fa-copy"></i> Copiar
                    </button>
                </div>
            </div>

            <div class="instruction-row">
                <div class="pix-instructions">
                    <h4>Como pagar com PIX:</h4>
                    <p>1. Abra o aplicativo do seu banco</p>
                    <p>2. Escolha a opção PIX</p>
                    <p>3. Escaneie o QR Code ou cole o código PIX</p>
                    <p>4. Confirme as informações e realize o pagamento</p>
                </div>
                <?php if (!empty($qr_code_img)): ?>
                <div class="qr-code">
                    <img src="data:image/png;base64,<?php echo $e($qr_code_img); ?>" alt="QR Code PIX">
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div id="boleto-content" class="payment-content <?php echo !$has_pix ? 'active' : ''; ?>">
            <div class="payment-title">Pagamento por Boleto</div>

            <?php if (!$is_registered): ?>
            <div id="boleto-loading" class="loading-indicator">
                <i class="fa fa-circle-o-notch fa-spin"></i>
                <p>O banco está registrando o boleto. Os dados aparecem aqui em instantes.</p>
            </div>
            <div id="boleto-late" style="display:none;">
                <div class="barcode-section">
                    <h4>Linha Digitável</h4>
                    <div class="pix-code" style="position: relative; width: 100%;">
                        <input type="text" id="line-input" class="copy-input" value="" readonly style="width: 100%; padding-right: 150px;">
                        <button class="copy-button" onclick="copyToClipboard('line-input', this)" style="position: absolute; right: 0; top: 0; border-left: none;">
                            <i class="fa fa-copy"></i> Copiar Linha Digitável
                        </button>
                    </div>
                </div>
                <div class="buttons-section">
                    <a id="late-html" href="#" target="_blank" rel="noopener" class="copy-button" style="background-color: #e38a17; color: #ffffff;"><i class="fa fa-external-link"></i> Visualizar Boleto</a>
                    <a id="late-pdf" href="#" target="_blank" rel="noopener" class="copy-button"><i class="fa fa-file-pdf-o"></i> Baixar PDF</a>
                </div>
            </div>
            <?php else: ?>
            <div id="boleto-content-inner">
                <?php if (!empty($barcode_svg)): ?>
                <div class="barcode-section">
                    <h4>Código de Barras</h4>
                    <div class="barcode-svg"><?php echo $barcode_svg; ?></div>
                </div>
                <?php endif; ?>

                <div class="barcode-section">
                    <h4>Linha Digitável</h4>
                    <div class="pix-code" style="position: relative; width: 100%;">
                        <input type="text" id="line-input" class="copy-input" value="<?php echo $e($line); ?>" readonly style="width: 100%; padding-right: 150px;">
                        <button class="copy-button" onclick="copyToClipboard('line-input', this)" style="position: absolute; right: 0; top: 0; border-left: none;">
                            <i class="fa fa-copy"></i> Copiar Linha Digitável
                        </button>
                    </div>
                </div>

                <div class="barcode-section">
                    <h4>Data de Vencimento</h4>
                    <p id="due-date-display"><?php echo !empty($due_date) ? $e(date('d/m/Y', strtotime($due_date))) : ''; ?></p>
                </div>

                <div class="buttons-section">
                    <a href="<?php echo $e($boleto_html_url); ?>" target="_blank" rel="noopener" class="copy-button" style="background-color: #e38a17; color: #ffffff;">
                        <i class="fa fa-external-link"></i> Visualizar Boleto
                    </a>
                    <a href="<?php echo $e($boleto_pdf_url); ?>" target="_blank" rel="noopener" class="copy-button">
                        <i class="fa fa-file-pdf-o"></i> Baixar PDF
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="payment-status">
        <?php if ($is_paid): ?>
            <p style="font-weight: bold; color: green;">PAGAMENTO REALIZADO COM SUCESSO!</p>
        <?php else: ?>
            <p>Aguardando confirmação do pagamento...</p>
            <div class="buttons-section">
                <a class="copy-button" href="<?php echo $e($invoice_url); ?>">
                    <i class="fa fa-refresh"></i> Já realizei o pagamento
                </a>
            </div>
        <?php endif; ?>
        </div>

        <style>
        .buttons-section a:hover {
            background-color: #cc7a14;
            color: #ffffff;
        }
        .barcode-svg svg { max-width: 100%; height: 60px; }
        </style>
    </div>

    <script>
    function copyToClipboard(inputId, button) {
        var input = document.getElementById(inputId);
        input.select();
        document.execCommand('copy');
        var original = button.innerHTML;
        button.textContent = 'Copiado!';
        setTimeout(function () { button.innerHTML = original; }, 2000);
    }

    function showPaymentTab(tab) {
        document.querySelectorAll('.payment-tab').forEach(function (el) { el.classList.remove('active'); });
        document.querySelectorAll('.payment-content').forEach(function (el) { el.classList.remove('active'); });
        var content = document.getElementById(tab + '-content');
        if (content) { content.classList.add('active'); }
        document.querySelectorAll('.payment-tab').forEach(function (el) {
            if (el.getAttribute('onclick').indexOf("'" + tab + "'") !== -1) { el.classList.add('active'); }
        });
    }

    (function () {
        var paid = <?php echo $is_paid ? 'true' : 'false'; ?>;
        var registered = <?php echo $is_registered ? 'true' : 'false'; ?>;
        var statusUrl = <?php echo json_encode($status_url); ?>;
        var invoiceUrl = <?php echo json_encode($invoice_url); ?>;
        if (paid) { return; }
        var attempts = 0;
        var timer = setInterval(function () {
            attempts++;
            // Para depois de 30 minutos
            if (attempts > 360) { clearInterval(timer); return; }
            fetch(statusUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.status === 'paid') {
                        clearInterval(timer);
                        // A página do boleto é resposta de um POST: volta para a fatura por GET
                        window.location.href = invoiceUrl;
                        return;
                    }
                    if (!registered && data.boleto_ready && data.boleto) {
                        registered = true;
                        document.getElementById('line-input').value = data.boleto.line;
                        document.getElementById('late-html').href = data.boleto.html_url;
                        document.getElementById('late-pdf').href = data.boleto.pdf_url;
                        document.getElementById('boleto-loading').style.display = 'none';
                        document.getElementById('boleto-late').style.display = 'block';
                    }
                })
                .catch(function () {});
        }, 5000);
    })();
    </script>
</div>
