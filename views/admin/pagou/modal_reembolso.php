<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<!-- Modal de Reembolso -->
<div class="modal fade" id="modalReembolso" tabindex="-1" role="dialog" aria-labelledby="modalReembolsoLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <!-- Formulário que envia os dados para processar o reembolso -->
            <?= form_open('pagou/processar_reembolso', ['id' => 'formReembolso']); ?>
                <div class="modal-header">
                    <h2 class="modal-title text-center" id="modalReembolsoLabel" style="font-weight: bold; font-size: 2rem;">
                        Reembolso de Pagamento
                    </h2>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div><!-- /.modal-header -->

                <div class="modal-body">
                    <?php
                    // Logando a abertura do modal
                    log_message('info', 'Modal de Reembolso aberto.');
                    ?>

                    <!-- Campo hidden para vincular a transação ao reembolso -->
                    <input type="hidden" id="transaction_id" name="transaction_id">

                    <!-- Campo CSRF -->
                    <input type="hidden" name="<?= $this->security->get_csrf_token_name(); ?>" value="<?= $this->security->get_csrf_hash(); ?>" />

                    <!-- Exibição de informações do cliente e fatura -->
                    <p class="text-center">
                        <strong>Cliente:</strong> <span id="reembolsoCliente"></span>
                    </p>
                    <p class="text-center">
                        <strong>Fatura:</strong> <span id="reembolsoInvoice"></span>
                    </p>
                    <hr>

                    <p class="text-center" style="font-size: 1rem; font-style: italic;">
                        O reembolso é destinado exclusivamente para pagamentos realizados na modalidade PIX ou PIX via Boleto Bancário.
                    </p>
                    <hr>

                    <!-- Opções de Valor do Reembolso -->
                    <div class="form-group">
                        <label for="amount">Valor do Reembolso:</label><br>
                        <input type="radio" id="valorTotal" name="amount_type" value="total" checked>
                        <label for="valorTotal">Valor Total: R$<span id="valorTotalSpan"></span></label>
                        <br>
                        <input type="radio" id="outroValor" name="amount_type" value="partial">
                        <label for="outroValor">Outro Valor:</label>
                        <input type="text" id="outroValorInput" name="other_amount" class="form-control" 
                               style="display: none;" placeholder="Digite o valor">
                    </div>

                    <?php
                    // Logando os elementos do formulário
                    log_message('debug', 'Elementos do formulário de reembolso renderizados.');
                    ?>

                    <!-- Motivo do Reembolso -->
                    <div class="form-group">
                        <label for="reason">Motivo do Cancelamento:</label>
                        <textarea id="reason" name="reason" class="form-control" rows="3" required></textarea>
                    </div>
                    <hr>

                    <!-- Confirmação Final -->
                    <div class="form-group text-center">
                        <input type="checkbox" id="confirmRefund" name="confirm_refund" required>
                        <label for="confirmRefund">
                            Ao clicar em 'Continuar', o processo de reembolso não poderá ser cancelado!
                        </label>
                    </div>
                </div><!-- /.modal-body -->

                <div class="modal-footer">
                    <!-- Botão para Submeter o Reembolso -->
                    <button type="submit" class="btn btn-primary" id="btnContinuar" disabled>CONTINUAR</button>
                </div><!-- /.modal-footer -->
            <?= form_close(); ?>
        </div><!-- /.modal-content -->
    </div><!-- /.modal-dialog -->
</div><!-- /.modal -->

<?php init_tail(); ?>

<script>
    // Logando o carregamento do script
    console.log('Script do Modal de Reembolso carregado.');

    /**
     * Exibe/oculta o campo "Outro Valor" caso seja selecionado reembolso parcial.
     */
    $('input[name="amount_type"]').on('change', function () {
        if ($('#outroValor').is(':checked')) {
            $('#outroValorInput').show().prop('required', true);
            console.log('Opção "Outro Valor" selecionada. Exibindo campo de input.');
            <?php log_message('info', 'Opção "Outro Valor" selecionada no modal de reembolso.'); ?>
        } else {
            $('#outroValorInput').hide().prop('required', false);
            console.log('Opção "Valor Total" selecionada. Escondendo campo de input.');
            <?php log_message('info', 'Opção "Valor Total" selecionada no modal de reembolso.'); ?>
        }
    });

    /**
     * Habilita o botão "CONTINUAR" somente após confirmar o reembolso.
     */
    $('#confirmRefund').on('change', function () {
        $('#btnContinuar').prop('disabled', !this.checked);
        if(this.checked) {
            console.log('Checkbox de confirmação marcado. Botão "Continuar" habilitado.');
            <?php log_message('info', 'Checkbox de confirmação de reembolso marcado. Botão "Continuar" habilitado.'); ?>
        } else {
            console.log('Checkbox de confirmação desmarcado. Botão "Continuar" desabilitado.');
            <?php log_message('info', 'Checkbox de confirmação de reembolso desmarcado. Botão "Continuar" desabilitado.'); ?>
        }
    });

    /**
     * Loga a submissão do formulário de reembolso.
     */
    $('#formReembolso').on('submit', function(e) {
        console.log('Formulário de Reembolso submetido.');
        <?php log_message('info', 'Formulário de Reembolso submetido pelo usuário.'); ?>
        // Você pode adicionar mais logs aqui se necessário
    });
</script>
