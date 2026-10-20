<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars('Pagamento - Fatura #' . format_invoice_number($invoice->id), ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        /* Estilos CSS */
        .floating-arrow {
            position: fixed;
            right: 30px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            background: linear-gradient(145deg, #ffffff, #f6f6f6);
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            z-index: 1000;
            transition: all 0.3s ease;
            opacity: 1;
            border: 1px solid rgba(0,0,0,0.05);
        }
        .floating-arrow:hover {
            transform: translateY(-50%) scale(1.05);
            box-shadow: 0 6px 25px rgba(0,0,0,0.15);
        }
        .floating-arrow.hidden {
            opacity: 0;
            pointer-events: none;
        }
        .arrow-icon {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }
        .arrow-icon i {
            font-size: 28px;
            color: #0066cc;
            animation: bounce 2s infinite;
        }
        .arrow-text {
            font-size: 13px;
            font-weight: 500;
            color: #0066cc;
            white-space: nowrap;
            text-align: center;
        }
        .payment-status {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e5e5e5;
        }
        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% {
                transform: translateY(0);
            }
            40% {
                transform: translateY(-8px);
            }
            60% {
                transform: translateY(-4px);
            }
        }
        
        .refresh-button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background-color: #0069D9;
    color: #fff;
    border: none;
    padding: 8px 16px;
    margin-left: 10px;
    border-radius: 4px;
    cursor: pointer;
    font-weight: 600;
    transition: background-color 0.3s ease;
}

.refresh-button:hover {
    background-color: #0056b3;
}

.refresh-button i {
    font-size: 16px;
}

    </style>
</head>
<body>
    <div class="container" style="margin-top: 30px;">
        <div class="floating-arrow" id="scrollArrow">
            <div class="arrow-icon">
                <i class="fa fa-arrow-down"></i>
                <span class="arrow-text"><?php echo htmlspecialchars('Clique para visualizar a fatura', ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        </div>

        <div class="row">
            <div class="col-md-8 col-md-offset-2">
                <div class="panel_s">
                    <div class="panel-body">
                        <div class="row">
                            <div class="col-md-12">
                                <h4 class="no-margin">
                                    <?php echo htmlspecialchars(_l('invoice') . ' - ' . format_invoice_number($invoice->id), ENT_QUOTES, 'UTF-8'); ?>
                                </h4>
                                <hr />
                            </div>

                            <div class="col-md-12 text-center">
                                <h4><?php echo htmlspecialchars('Pagamento via PIX', ENT_QUOTES, 'UTF-8'); ?></h4>
                                <p><?php echo htmlspecialchars('Escaneie o QR Code abaixo com o aplicativo do seu banco', ENT_QUOTES, 'UTF-8'); ?></p>
                                <?php if (!empty($qr_code)): ?>
                                <div class="qr-code-container">
                                    <img src="data:image/png;base64,<?php echo htmlspecialchars($qr_code, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars('QR Code PIX', ENT_QUOTES, 'UTF-8'); ?>" style="max-width: 300px;">
                                </div>
                                <?php endif; ?>
                                <p class="text-muted"><?php echo htmlspecialchars('ou', ENT_QUOTES, 'UTF-8'); ?></p>
                                <p><?php echo htmlspecialchars('Copie o código PIX abaixo:', ENT_QUOTES, 'UTF-8'); ?></p>
                                <div class="form-group">
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($qr_code_text, ENT_QUOTES, 'UTF-8'); ?>" readonly>
                                    <button class="btn btn-info btn-block" onclick="copyPixCode(this)" style="margin-top: 10px;">
                                        <?php echo htmlspecialchars('Copiar código PIX', ENT_QUOTES, 'UTF-8'); ?>
                                    </button>
                                </div>
                                <hr>
                                <div class="alert alert-info">
                                    <p><strong><?php echo htmlspecialchars('Instruções:', ENT_QUOTES, 'UTF-8'); ?></strong></p>
                                    <ol class="text-left">
                                        <li><?php echo htmlspecialchars('Abra o aplicativo do seu banco', ENT_QUOTES, 'UTF-8'); ?></li>
                                        <li><?php echo htmlspecialchars('Escolha a opção PIX', ENT_QUOTES, 'UTF-8'); ?></li>
                                        <li><?php echo htmlspecialchars('Escaneie o QR Code ou cole o código copiado', ENT_QUOTES, 'UTF-8'); ?></li>
                                        <li><?php echo htmlspecialchars('Confirme as informações e finalize o pagamento', ENT_QUOTES, 'UTF-8'); ?></li>
                                    </ol>
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
        <button class="refresh-button" onclick="window.location.href = <?php echo htmlspecialchars(json_encode(site_url('invoice/' . (int) $invoice->id . '/' . $invoice->hash)), ENT_QUOTES, 'UTF-8'); ?>;">
            <i class="fa fa-refresh"></i>
            <?php echo htmlspecialchars('Pagamento Realizado?', ENT_QUOTES, 'UTF-8'); ?>
        </button>
    <?php endif; ?>
</div>

                            </div>
                        </div> 
                    </div> 
                </div>
            </div> 
        </div> 
    </div> 

    <script>
        function copyPixCode(button) {
            const input = button.previousElementSibling;
            input.select();
            document.execCommand('copy');
            button.textContent = '<?php echo htmlspecialchars('Código copiado!', ENT_QUOTES, 'UTF-8'); ?>';
            setTimeout(() => {
                button.textContent = '<?php echo htmlspecialchars('Copiar código PIX', ENT_QUOTES, 'UTF-8'); ?>';
            }, 2000);
        }

        function scrollToBottom() {
            window.scrollTo({
                top: document.documentElement.scrollHeight,
                behavior: 'smooth'
            });
        }

        const scrollArrow = document.getElementById('scrollArrow');
        let lastScrollPosition = 0;

        window.addEventListener('scroll', () => {
            const currentScroll = window.pageYOffset || document.documentElement.scrollTop;
            const maxScroll = document.documentElement.scrollHeight - window.innerHeight;

            if (currentScroll > maxScroll * 0.2) {
                scrollArrow.classList.add('hidden');
            } else {
                scrollArrow.classList.remove('hidden');
            }

            lastScrollPosition = currentScroll;
        });

        scrollArrow.addEventListener('click', scrollToBottom);
    </script>
<script>
    const paymentCompleted = <?php echo ($invoice->status == 2) ? 'true' : 'false'; ?>;
    // A página do Pix é resposta de um POST: depois de pago, volta para a fatura por GET
    const invoiceUrl = <?php echo json_encode(site_url('invoice/' . (int) $invoice->id . '/' . $invoice->hash)); ?>;
    let checkInterval;
    let checkAttempts = 0;
    
    function checkPaymentStatus() {
        checkAttempts++;
        // Para depois de 30 minutos
        if (paymentCompleted || checkAttempts > 360) {
            if (checkInterval) {
                clearInterval(checkInterval);
            }
            return;
        }
        
        fetch(<?php echo json_encode(site_url('pagou/payment_status/check/' . (int) $invoice->id . '/' . $invoice->hash)); ?>, {
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
                if (checkInterval) {
                    clearInterval(checkInterval);
                }
                window.location.href = invoiceUrl;
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
</body>
</html>