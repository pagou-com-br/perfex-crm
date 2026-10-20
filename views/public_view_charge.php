<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title><?php echo _l('Visualização da Cobrança PIX Avulsa'); ?></title>
   <style>
       body {
           font-family: Arial, sans-serif;
           background: #f5f5f5;
           padding: 20px;
       }
       .container {
           max-width: 600px;
           margin: auto;
           background: #fff;
           padding: 20px;
           border: 1px solid #ddd;
       }
       .text-center {
           text-align: center;
       }
       hr {
           border-top: 2px solid #ccc;
           width: 80%;
           margin: 20px auto;
       }
       .form-group {
           margin-bottom: 15px;
       }
       .btn {
           display: block;
           width: 100%;
           padding: 10px;
           font-size: 16px;
           cursor: pointer;
       }
       .alert {
           padding: 10px;
           border: 1px solid #e0e0e0;
           background-color: #f8d7da;
           color: #721c24;
           margin-bottom: 15px;
           text-align: center;
       }
   </style>
</head>
<body>
<div class="container">
   <h3 class="text-center"><?php echo _l('Visualização da Cobrança PIX Avulsa'); ?></h3>
   <hr>
   
   <?php if (empty($unlocked)): ?>
       <?php if (!empty($error)): ?>
           <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
       <?php endif; ?>
       <!-- Exibe o formulário para solicitar o CPF -->
       <p class="text-center"><?php echo _l('Digite seu CPF para desbloquear a visualização da cobrança'); ?></p>
       <?php echo form_open(site_url('pagou/public_charge/view/' . rawurlencode($charge->link))); ?>
           <div class="form-group">
               <label for="cpf_verify"><?php echo _l('CPF'); ?></label>
               <input type="text" name="cpf_verify" id="cpf_verify" class="form-control" required>
           </div>
           <button type="submit" class="btn btn-primary"><?php echo _l('Visualizar Cobrança'); ?></button>
       <?php echo form_close(); ?>
   <?php else: ?>
       <?php if(isset($error)): ?>
           <div class="alert">
               <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
           </div>
           <?php echo form_open(site_url('pagou/public_charge/view/' . rawurlencode($charge->link))); ?>
               <div class="form-group">
                   <label for="cpf_verify"><?php echo _l('CPF'); ?></label>
                   <input type="text" name="cpf_verify" id="cpf_verify" class="form-control" required>
               </div>
               <button type="submit" class="btn btn-primary"><?php echo _l('Visualizar Cobrança'); ?></button>
           <?php echo form_close(); ?>
       <?php else: ?>
           <!-- CPF validado: exibe os detalhes da cobrança -->
           <div class="text-center">
               <h4><?php echo _l('Detalhes da Cobrança PIX'); ?></h4>
               <p><strong><?php echo _l('Cliente:'); ?></strong> <?php echo htmlspecialchars($charge->nome, ENT_QUOTES, 'UTF-8'); ?></p>
               <p><strong><?php echo _l('Descrição:'); ?></strong> <?php echo htmlspecialchars($charge->descricao, ENT_QUOTES, 'UTF-8'); ?></p>
               <p><strong><?php echo _l('Valor:'); ?></strong> <?php echo htmlspecialchars($charge->valor, ENT_QUOTES, 'UTF-8'); ?></p>
               <p><strong><?php echo _l('Data de Emissão:'); ?></strong> <?php echo htmlspecialchars($charge->data_emissao, ENT_QUOTES, 'UTF-8'); ?></p>
               <hr>
               
               <?php if (!empty($charge->qr_code_img)): ?>
                   <div class="qr-code-container text-center" style="margin-bottom:20px;">
                       <img src="data:image/png;base64,<?php echo htmlspecialchars($charge->qr_code_img, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo _l('QR Code PIX'); ?>" style="max-width:300px;">
                   </div>
               <?php else: ?>
                   <p class="text-center"><?php echo _l('QR Code não disponível.'); ?></p>
               <?php endif; ?>
               
               <?php if (!empty($charge->qr_code_text)): ?>
                   <p class="text-center"><?php echo _l('Ou copie o código PIX abaixo:'); ?></p>
                   <div class="form-group">
                       <input type="text" class="form-control" value="<?php echo htmlspecialchars($charge->qr_code_text, ENT_QUOTES, 'UTF-8'); ?>" readonly>
                       <button class="btn btn-info" onclick="copyPixCode(this)" style="margin-top:10px;"><?php echo _l('Copiar código PIX'); ?></button>
                   </div>
               <?php endif; ?>
           </div>
           <script>
               function copyPixCode(button) {
                   var input = button.previousElementSibling;
                   input.select();
                   document.execCommand('copy');
                   button.textContent = '<?php echo _l('Código copiado!'); ?>';
                   setTimeout(function() {
                       button.textContent = '<?php echo _l('Copiar código PIX'); ?>';
                   }, 2000);
               }
           </script>
       <?php endif; ?>
   <?php endif; ?>
</div>
</body>
</html>
