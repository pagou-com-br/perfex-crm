<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Detalhes da Cobrança PIX Avulsa</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            background-color: #f1f1f1;
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
        }
        .container {
            max-width: 700px;
            margin: 40px auto;
            padding: 20px;
        }
        .panel {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        h3, h4 {
            text-align: center;
            margin: 0 0 20px 0;
            color: #333;
        }
        hr {
            border: none;
            border-top: 2px solid #ccc;
            margin: 20px auto;
            width: 80%;
        }
        .text-center { text-align: center; }
        .qr-code-container {
            text-align: center;
            margin: 20px 0;
        }
        .qr-code-container img {
            max-width: 300px;
        }
        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
            padding: 15px;
            margin-top: 20px;
            border: 1px solid #bee5eb;
            border-radius: 4px;
        }
        .btn {
            display: inline-block;
            border: none;
            border-radius: 4px;
            width: 100%;
            padding: 12px;
            margin-top: 10px;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
        }
        .btn-info {
            background-color: #17a2b8;
            color: #fff;
        }
        .btn-info:hover {
            background-color: #138496;
        }
        .form-control {
            width: 100%;
            padding: 10px;
            border-radius: 4px;
            border: 1px solid #ccc;
        }

        /* Novo estilo para botão e container de WhatsApp */
        .btn-success {
            background-color: #28a745;
            color: #fff;
            border-radius: 4px;
            width: auto;
            padding: 10px 20px;
            margin-top: 15px;
        }
        .btn-success:hover {
            background-color: #218838;
        }
        .whatsapp-container {
            margin-top: 20px;
            padding: 10px;
            border: 1px solid #ccc;
            cursor: pointer;
            display: none;
            white-space: pre-wrap;
            border-radius: 4px;
        }

        .whatsapp-icon {
            margin-right: 8px;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="panel">
        <h3>Cobrança PIX Avulsa</h3>
        <hr>

        <?php
            // Exibimos os detalhes da cobrança imediatamente.
            $qr_code      = isset($charge->qr_code)      ? $charge->qr_code      : '';
            $qr_code_text = isset($charge->qr_code_text) ? $charge->qr_code_text : '';
            $qr_code_img  = isset($charge->qr_code_img)  ? $charge->qr_code_img  : '';
        ?>

        <div class="text-center">
            <h4>Detalhes da Cobrança PIX</h4>
            <p><strong>Cliente:</strong> <?php echo htmlspecialchars($charge->nome, ENT_QUOTES, 'UTF-8'); ?></p>
            <p><strong>Descrição:</strong> <?php echo htmlspecialchars($charge->descricao, ENT_QUOTES, 'UTF-8'); ?></p>
            <p><strong>Valor:</strong> <?php echo htmlspecialchars($charge->valor, ENT_QUOTES, 'UTF-8'); ?></p>
            <p><strong>Data de Emissão:</strong> <?php echo htmlspecialchars($charge->data_emissao, ENT_QUOTES, 'UTF-8'); ?></p>
            <p><strong>Status:</strong> <?php echo htmlspecialchars($charge->status, ENT_QUOTES, 'UTF-8'); ?></p>
            <hr>

            <!-- Exibir QR Code por imagem (preferencialmente qr_code_img) -->
            <?php if (!empty($qr_code_img)): ?>
                <div class="qr-code-container">
                    <img src="data:image/png;base64,<?php echo trim(htmlspecialchars($qr_code_img, ENT_QUOTES, 'UTF-8')); ?>" alt="QR Code PIX">
                </div>
            <?php elseif (!empty($qr_code)): ?>
                <div class="qr-code-container">
                    <img src="data:image/png;base64,<?php echo trim(htmlspecialchars($qr_code, ENT_QUOTES, 'UTF-8')); ?>" alt="QR Code PIX">
                </div>
            <?php else: ?>
                <p>QR Code não disponível.</p>
            <?php endif; ?>

            <!-- Exibir código PIX (texto) -->
            <?php if (!empty($qr_code_text)): ?>
                <p>Ou copie o código PIX abaixo:</p>
                <div>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($qr_code_text, ENT_QUOTES, 'UTF-8'); ?>" readonly>
                    <button class="btn btn-info" onclick="copyPixCode(this)">Copiar código PIX</button>
                </div>
                <div class="alert-info">
                    <strong>Instruções:</strong>
                    <ol style="text-align:left;">
                        <li>Abra o aplicativo do seu banco e selecione PIX</li>
                        <li>Escaneie o QR Code ou cole o código copiado</li>
                        <li>Confirme os dados e finalize o pagamento</li>
                    </ol>
                </div>
            <?php else: ?>
                <p>Código PIX não disponível.</p>
            <?php endif; ?>
        </div>

        <!-- ======================================= -->
        <!-- == ADIÇÃO/ALTERAÇÃO: Botão WhatsApp  == -->
        <!-- ======================================= -->
        <div class="text-center">
            <button class="btn btn-success" onclick="showWhatsAppText()">
                <i class="fa fa-whatsapp whatsapp-icon"></i> Compartilhar via WhatsApp
            </button>
        </div>

        <!-- Caixa de texto com as informações formatadas (oculta inicialmente) -->
        <div id="whatsAppTextContainer" class="whatsapp-container" onclick="copyWhatsAppText()">
            <!-- Será preenchido dinamicamente via JavaScript -->
        </div>

        <script>
            // Copiar código PIX
            function copyPixCode(button) {
                var input = button.previousElementSibling;
                input.select();
                document.execCommand('copy');
                button.textContent = 'Código copiado!';
                setTimeout(function() {
                    button.textContent = 'Copiar código PIX';
                }, 2000);
            }

            // Exibir texto formatado para WhatsApp
            function showWhatsAppText() {
                // Monta o texto usando template literal para preservar quebras de linha e formatações
                var text = `*Cobrança PIX Avulsa*\n\n` +
                           `*Detalhes da Cobrança PIX*\n` +
                           `*Cliente:* <?php echo addslashes($charge->nome); ?>\n` +
                           `*Descrição:* <?php echo addslashes($charge->descricao); ?>\n\n` +
                           `*Valor:* R$<?php echo addslashes($charge->valor); ?>\n` +
                           `*Data de Emissão:* <?php echo addslashes($charge->data_emissao); ?>\n\n` +
                           `*código PIX abaixo:*\n` +
                           `<?php echo addslashes($qr_code_text); ?>\n\n` +
                           `*Instruções:*\n` +
                           `*1 -* Abra o aplicativo do seu banco e selecione PIX\n` +
                           `*2 -* Escaneie o QR Code ou cole o código copiado\n` +
                           `*3 -* Confirme os dados e finalize o pagamento\n`;

                var container = document.getElementById("whatsAppTextContainer");
                container.textContent = text;
                container.style.display = 'block';
                alert('Clique no texto para copiá-lo.');
            }

            // Copiar texto do container para a área de transferência
            function copyWhatsAppText() {
                var container = document.getElementById("whatsAppTextContainer");
                var range = document.createRange();
                range.selectNode(container);
                window.getSelection().removeAllRanges();
                window.getSelection().addRange(range);

                try {
                    var successful = document.execCommand('copy');
                    if (successful) {
                        alert('Texto copiado para a área de transferência!');
                    } else {
                        alert('Não foi possível copiar o texto.');
                    }
                } catch (err) {
                    alert('Erro ao copiar o texto: ' + err);
                }
                // Limpa a seleção
                window.getSelection().removeAllRanges();
            }
        </script>

    </div>
</div>

</body>
</html>
