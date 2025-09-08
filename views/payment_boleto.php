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
        $has_pix = !empty($qr_code_text) && !empty($qr_code_img);
        $has_barcode = !empty($barcode);
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
                    <input type="text" class="copy-input" value="<?php echo $qr_code_text; ?>" readonly style="width: 100%; padding-right: 80px;">
                    <button class="copy-button" onclick="copyToClipboard('pix')" style="position: absolute; right: 0; top: 0; border-left: none;">
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

                <div class="qr-code">
                    <img src="data:image/png;base64,<?php echo $qr_code_img; ?>" alt="QR Code PIX">
                </div>
            </div>

            <div class="payment-status">
            <?php if ($invoice->status == 2): ?>
                <p style="font-weight: bold; color: green;">
                    <?php echo htmlspecialchars('PAGAMENTO REALIZADO COM SUCESSO!', ENT_QUOTES, 'UTF-8'); ?>
                </p>
            <?php else: ?>
                <p><?php echo htmlspecialchars('Aguardando confirmação do pagamento...', ENT_QUOTES, 'UTF-8'); ?></p>
                <div class="spinner-border text-primary" role="status" style="vertical-align: middle;">
                    <span class="sr-only"><?php echo htmlspecialchars('Carregando...', ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="buttons-section">
                    <button class="copy-button" onclick="window.location.reload();">
                        <i class="fa fa-refresh"></i> Já realizei o pagamento
                    </button>
                </div>
            <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div id="boleto-content" class="payment-content <?php echo !$has_pix ? 'active' : ''; ?>">
            <div class="payment-title">Pagamento por Boleto</div>

            <div id="boleto-loading" class="loading-indicator" <?php echo $has_barcode ? 'style="display:none;"' : ''; ?>>
                <i class="fa fa-circle-o-notch fa-spin"></i>
                <p>Carregando informações do boleto...</p>
            </div>
            <div class="buttons-section">
                    <button class="copy-button" onclick="window.location.reload();">
                        <i class="fa fa-refresh"></i> Click para Atualizar!
                    </button>
                </div>

            <div id="boleto-content-inner" <?php echo !$has_barcode ? 'style="display:none;"' : ''; ?>>
                <div class="barcode-section">
                    <h4>Código de Barras</h4>
                    <img id="barcode-image" src="<?php echo $has_barcode ? site_url('pagou/generate_barcode/' . urlencode($barcode)) : ''; ?>" alt="Código de Barras do Boleto">
                </div>

                <div class="barcode-section">
                    <h4>Linha Digitável</h4>
                    <div class="pix-code" style="position: relative; width: 100%;">
                        <input type="text" id="barcode-input" class="copy-input" value="<?php echo $has_barcode ? chunk_split($barcode, 5, ' ') : ''; ?>" readonly style="width: 100%; padding-right: 150px;">
                        <button class="copy-button" onclick="copyToClipboard('boleto')" style="position: absolute; right: 0; top: 0; border-left: none;">
                            <i class="fa fa-copy"></i> Copiar Linha Digitável
                        </button>
                    </div>
                </div>

                <div class="barcode-section">
                    <h4>Data de Vencimento</h4>
                    <p id="due-date-display"><?php echo !empty($due_date) ? date('d/m/Y', strtotime($due_date)) : ''; ?></p>
                </div>
            </div>

            <div class="payment-status">
            <?php if ($invoice->status == 2): ?>
                <p style="font-weight: bold; color: green;">
                    <?php echo htmlspecialchars('PAGAMENTO REALIZADO COM SUCESSO!', ENT_QUOTES, 'UTF-8'); ?>
                </p>
            <?php else: ?>
                <p><?php echo htmlspecialchars('Aguardando confirmação do pagamento...', ENT_QUOTES, 'UTF-8'); ?></p>
                <div class="spinner-border text-primary" role="status" style="vertical-align: middle;">
                    <span class="sr-only"><?php echo htmlspecialchars('Carregando...', ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="buttons-section">
                    <a href="https://fatura.pagou.com.br/boleto/<?php echo $pagou_transaction_id; ?>" target="_blank" class="copy-button" style="background-color: #e38a17; color: #ffffff;">
                        <i class="fa fa-external-link"></i> Visualizar Boleto
                    </a>
                    <button class="copy-button" onclick="window.location.reload();">
                        <i class="fa fa-refresh"></i> Já realizei o pagamento
                    </button>
                </div>
            <?php endif; ?>
            </div>
        </div>

        <style>
        .buttons-section a:hover {
            background-color: #cc7a14;
            color: #ffffff;
        }
        </style>
    </div>

    <script>
    function copyToClipboard(type) {
        const input = document.querySelector(type === 'pix' ? 
            '#pix-content .copy-input' : '#boleto-content .copy-input');
        input.select();
        document.execCommand('copy');

        const button = event.target.closest('.copy-button');
        const originalText = button.innerHTML;
        button.innerHTML = '<i class="fa fa-check"></i> Copiado!';
        setTimeout(() => {
            button.innerHTML = originalText;
        }, 2000);
    }
    
    function showPaymentTab(tabName) {
        const contents = document.querySelectorAll('.payment-content');
        contents.forEach(content => {
            content.classList.remove('active');
        });
        
        const tabs = document.querySelectorAll('.payment-tab');
        tabs.forEach(tab => {
            tab.classList.remove('active');
        });
        
        document.getElementById(tabName + '-content').classList.add('active');
        event.target.classList.add('active');
        
        // Se o tab de boleto foi selecionado e os dados ainda não estão carregados, carregue-os
        if (tabName === 'boleto' && document.getElementById('barcode-input').value.trim() === '') {
            loadBoletoData();
        }
    }
    
    // Função para carregar os dados do boleto
    function loadBoletoData() {
        const invoiceId = <?php echo $invoice->id; ?>;
        const transactionId = '<?php echo $pagou_transaction_id; ?>';
        
        if (!invoiceId || !transactionId) {
            console.error('ID da fatura ou ID da transação não disponíveis');
            return;
        }
        
        // Mostrar indicador de carregamento
        document.getElementById('boleto-loading').style.display = 'block';
        document.getElementById('boleto-content-inner').style.display = 'none';
        
        fetch(`<?php echo site_url('pagou/get_boleto_data/'); ?>${invoiceId}/${transactionId}`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            cache: 'no-store'
        })
        .then(response => response.json())
        .then(data => {
            if (data && data.barcode) {
                // Atualizar os elementos com os dados do boleto
                const formattedBarcode = data.barcode.replace(/(.{5})/g, "$1 ").trim();
                document.getElementById('barcode-input').value = formattedBarcode;
                document.getElementById('barcode-image').src = `<?php echo site_url('pagou/generate_barcode/'); ?>${encodeURIComponent(data.barcode)}`;
                
                if (data.due_date) {
                    const date = new Date(data.due_date);
                    const formattedDate = new Intl.DateTimeFormat('pt-BR').format(date);
                    document.getElementById('due-date-display').textContent = formattedDate;
                }
                
                // Esconder indicador de carregamento e mostrar conteúdo
                document.getElementById('boleto-loading').style.display = 'none';
                document.getElementById('boleto-content-inner').style.display = 'block';
            } else {
                console.error('Dados do boleto não disponíveis:', data);
                // Tentar novamente após 2 segundos
                setTimeout(loadBoletoData, 2000);
            }
        })
        .catch(error => {
            console.error('Erro ao carregar dados do boleto:', error);
            // Tentar novamente após 3 segundos
            setTimeout(loadBoletoData, 3000);
        });
    }
    
    // Verificar se precisamos carregar os dados do boleto quando a página carrega
    document.addEventListener('DOMContentLoaded', function() {
        const boletoTab = document.querySelector('.payment-tab:not(.active)');
        const isShownFirst = boletoTab ? boletoTab.classList.contains('active') : false;
        
        if (isShownFirst || !document.getElementById('barcode-input').value.trim()) {
            loadBoletoData();
        }
    });
    </script>
    
    <script>
    const paymentCompleted = <?php echo ($invoice->status == 2) ? 'true' : 'false'; ?>;
    let checkInterval;
    
    function checkPaymentStatus() {
        if (paymentCompleted) {
            console.log('Pagamento já confirmado, parando as verificações.');
            if (checkInterval) {
                clearInterval(checkInterval);
            }
            return;
        }
        
        const invoiceId = <?php echo $invoice->id; ?>;
        fetch(`<?php echo site_url('pagou/check_payment_status/'); ?>${invoiceId}`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            cache: 'no-store'
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'paid') {
                console.log('Pagamento confirmado!');
                if (checkInterval) {
                    clearInterval(checkInterval);
                }
                window.location.reload();
            }
        })
        .catch(error => {
            console.error('Erro ao verificar status:', error);
        });
    }

    if (!paymentCompleted) {
        checkPaymentStatus();
        checkInterval = setInterval(checkPaymentStatus, 5000);
        
        window.addEventListener('beforeunload', () => {
            if (checkInterval) {
                clearInterval(checkInterval);
            }
        });
    } else {
        console.log('Pagamento já confirmado, não há necessidade de verificações.');
    }
    </script>
</div>