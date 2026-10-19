<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>

<?php
// Início do processamento da view de cobranças emitidas
log_message('info', 'Carregando a view "Cobranças PIX Emitidas".');
?>

<div id="wrapper">
    <div class="content">
        <div class="row">
            <div class="col-md-12">
                <div class="panel_s">
                    <div class="panel-body">
                        <h3 class="text-center" style="font-weight: bold; margin-bottom: 20px;">
                            <i class="fa fa-qrcode" style="margin-right: 10px;"></i>Cobranças PIX Emitidas
                        </h3>
                        
                        <!-- Filtros de Busca -->
                        <div class="row" style="margin-bottom: 20px;">
                            <div class="col-md-12">
                                <div class="panel_s">
                                    <div class="panel-heading">
                                        <h4 class="panel-title">Filtros de Busca</h4>
                                    </div>
                                    <div class="panel-body">
                                        <?php echo form_open(admin_url('pagou/cobrancas_emitidas'), ['method' => 'POST', 'id' => 'filtro-form']); ?>
                                        <div class="row">
                                            <div class="col-md-3">
                                                <div class="form-group">
                                                    <label for="client" class="control-label">Cliente</label>
                                                    <input type="text" name="client" id="client" class="form-control" value="<?php echo isset($filters['client']) ? $filters['client'] : ''; ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-group">
                                                    <label for="start_date" class="control-label">Data Inicial</label>
                                                    <div class="input-group date">
                                                        <input type="text" name="start_date" id="start_date" class="form-control datepicker" value="<?php echo isset($filters['start_date']) ? $filters['start_date'] : ''; ?>" autocomplete="off">
                                                        <div class="input-group-addon">
                                                            <i class="fa fa-calendar calendar-icon"></i>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-group">
                                                    <label for="end_date" class="control-label">Data Final</label>
                                                    <div class="input-group date">
                                                        <input type="text" name="end_date" id="end_date" class="form-control datepicker" value="<?php echo isset($filters['end_date']) ? $filters['end_date'] : ''; ?>" autocomplete="off">
                                                        <div class="input-group-addon">
                                                            <i class="fa fa-calendar calendar-icon"></i>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="form-group">
                                                    <label for="status" class="control-label">Status</label>
                                                    <select name="status" id="status" class="form-control selectpicker" data-width="100%">
                                                        <option value="">Todos</option>
                                                        <option value="paid" <?php echo (isset($filters['status']) && $filters['status'] == 'paid') ? 'selected' : ''; ?>>Pago</option>
                                                        <option value="pending" <?php echo (isset($filters['status']) && $filters['status'] == 'pending') ? 'selected' : ''; ?>>Pendente</option>
                                                        <option value="refunded" <?php echo (isset($filters['status']) && $filters['status'] == 'refunded') ? 'selected' : ''; ?>>Reembolsado</option>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-12">
                                                <button type="submit" class="btn btn-primary pull-right">
                                                    <i class="fa fa-search"></i> Filtrar
                                                </button>
                                                <button type="button" id="limpar-filtros" class="btn btn-default pull-right" style="margin-right: 5px;">
                                                    <i class="fa fa-eraser"></i> Limpar
                                                </button>
                                            </div>
                                        </div>
                                        <?php echo form_close(); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Estatísticas Rápidas -->
                        <div class="row" style="margin-bottom: 20px;">
                            <div class="col-md-3">
                                <div class="panel_s">
                                    <div class="panel-body" style="background-color: #2ecc71; color: white;">
                                        <h3 class="text-center"><?php echo count($transactions_pix); ?></h3>
                                        <p class="text-center">Transações PIX</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="panel_s">
                                    <div class="panel-body" style="background-color: #3498db; color: white;">
                                        <?php 
                                        $total_valor = 0;
                                        foreach ($transactions_pix as $trans) {
                                            if (isset($trans->amount_final)) {
                                                $total_valor += $trans->amount_final;
                                            }
                                        }
                                        ?>
                                        <h3 class="text-center">R$ <?php echo number_format($total_valor, 2, ',', '.'); ?></h3>
                                        <p class="text-center">Valor Total</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="panel_s">
                                    <div class="panel-body" style="background-color: #f39c12; color: white;">
                                        <?php 
                                        $pagos = 0;
                                        foreach ($transactions_pix as $trans) {
                                            if ($trans->status == 'paid' || $trans->status == '4') {
                                                $pagos++;
                                            }
                                        }
                                        ?>
                                        <h3 class="text-center"><?php echo $pagos; ?></h3>
                                        <p class="text-center">PIX Pagos</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="panel_s">
                                    <div class="panel-body" style="background-color: #e74c3c; color: white;">
                                        <?php 
                                        $reembolsados = 0;
                                        foreach ($transactions_pix as $trans) {
                                            if ($trans->status == 'refunded') {
                                                $reembolsados++;
                                            }
                                        }
                                        ?>
                                        <h3 class="text-center"><?php echo $reembolsados; ?></h3>
                                        <p class="text-center">PIX Reembolsados</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Tabela de resultados -->
                        <div class="row">
                            <div class="col-md-12">
                                <div class="table-responsive">
                                    <table class="table table-striped table-pix" id="tabela-transacoes">
                                        <thead>
                                            <tr>
                                                <th>Data Criação</th>
                                                <th>Fatura</th>
                                                <th>Cliente</th>
                                                <th>Valor (R$)</th>
                                                <th>Status</th>
                                                <th>Método</th>
                                                <th>ID Transação</th>
                                                <th>Data Pgto</th>
                                                <th>Ação</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <!-- Conteúdo da tabela será carregado via AJAX -->
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Reembolso PIX -->
<div class="modal fade" id="modalReembolsoPix" tabindex="-1" role="dialog" aria-labelledby="modalReembolsoPixLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header" style="background-color: #337ab7;">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close" style="color: white; opacity: 0.8;">
                    <span aria-hidden="true">&times;</span>
                </button>
                <h2 class="modal-title text-center" id="modalReembolsoPixLabel" style="font-weight: bold; font-size: 24px; color: white;">
                    <i class="fa fa-undo"></i> Reembolso de Pagamento PIX
                </h2>
            </div>
            <div class="modal-body">
                <!-- Informações do reembolso -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="info-panel" style="border-right: 1px solid #eee; padding-right: 15px;">
                            <div class="text-center" style="margin-bottom: 20px;">
                                <i class="fa fa-user-circle fa-3x" style="color: #3498db;"></i>
                                <h4>Informações do Cliente</h4>
                                <p><strong id="reembolsoCliente" style="font-size: 16px;">Cliente</strong></p>
                            </div>
                            <div class="text-center">
                                <i class="fa fa-file-text fa-3x" style="color: #f39c12;"></i>
                                <h4>Detalhes da Fatura</h4>
                                <p><strong id="reembolsoInvoice" style="font-size: 16px;">Fatura</strong></p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-panel" style="padding-left: 15px;">
                            <div class="text-center" style="margin-bottom: 20px;">
                                <i class="fa fa-qrcode fa-3x" style="color: #2ecc71;"></i>
                                <h4>Método de Pagamento</h4>
                                <p><span class="label label-info" style="font-size: 16px;"><i class="fa fa-qrcode"></i> PIX</span></p>
                            </div>
                            <div class="text-center">
                                <i class="fa fa-money fa-3x" style="color: #e74c3c;"></i>
                                <h4>Valor a Reembolsar</h4>
                                <p style="font-size: 20px; font-weight: bold;" id="valorExibicao">R$ 0,00</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <hr>
                
                <div class="row">
                    <div class="col-md-12">
                        <div class="alert alert-info">
                            <i class="fa fa-info-circle"></i> 
                            O reembolso PIX é processado pela API do Pagou e será enviado diretamente para a conta bancária de origem do cliente.
                        </div>
                    </div>
                </div>
                
                <!-- Opções de Valor do Reembolso -->
                <div class="form-group">
                    <label for="amount_type">Valor do Reembolso:</label>
                    <div class="radio" style="margin-bottom: 10px;">
                        <label style="font-weight: normal; display: flex; align-items: center;">
                            <input type="radio" id="valorTotal" name="amount_type" value="total" checked style="margin-right: 10px; width: 18px; height: 18px;">
                            <strong>Valor Total:</strong> R$ <span id="valorTotalSpan">0,00</span>
                        </label>
                    </div>
                    <!-- <div class="radio" style="margin-bottom: 10px;">
                        <label style="font-weight: normal; display: flex; align-items: center;">
                            <input type="radio" id="outroValor" name="amount_type" value="partial" style="margin-right: 10px; width: 18px; height: 18px;">
                            <strong>Valor Parcial</strong>
                        </label>
                    </div> -->
                    <div class="input-group" id="outroValorInputGroup" style="display: none; margin-top: 10px;">
                        <span class="input-group-addon">R$</span>
                        <input type="text" id="outroValorInput" class="form-control" placeholder="Digite o valor parcial" style="font-size: 16px;">
                    </div>
                </div>

                <!-- Motivo do Reembolso -->
                <div class="form-group">
                    <label for="refundReasonSelect">Motivo do Reembolso:</label>
                    <select id="refundReasonSelect" class="form-control" style="height: 40px; font-size: 15px;">
                        <option value="Cancelamento solicitado pelo cliente">Cancelamento solicitado pelo cliente</option>
                        <option value="Erro na cobrança">Erro na cobrança</option>
                        <option value="Produto/serviço não entregue">Produto/serviço não entregue</option>
                        <option value="Pagamento em duplicidade">Pagamento em duplicidade</option>
                        <option value="Outro motivo">Outro motivo (especificar)</option>
                    </select>
                    <textarea id="refundReason" class="form-control" rows="3" style="margin-top: 10px; display: none; font-size: 15px;" placeholder="Especifique o motivo do reembolso..."></textarea>
                </div>
                
                <hr>
                
                <!-- Confirmação Final -->
                <div class="form-group text-center">
                    <div class="checkbox">
                        <label style="font-size: 16px; color: #e74c3c; display: flex; align-items: center; justify-content: center;">
                            <input type="checkbox" id="confirmRefund" required style="margin-right: 10px; width: 18px; height: 18px;">
                            <strong>Atenção:</strong> Ao clicar em "Confirmar Reembolso", o processo não poderá ser cancelado!
                        </label>
                    </div>
                </div>
                
                <!-- Botões de ação -->
                <div class="row">
                    <div class="col-md-12 text-center">
                        <button type="button" class="btn btn-default" data-dismiss="modal">
                            <i class="fa fa-times"></i> Cancelar
                        </button>
                        <button class="btn btn-success btn-lg" id="btnConfirmarReembolso" disabled>
                            <i class="fa fa-check"></i> Confirmar Reembolso
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Detalhes da Transação -->
<div class="modal fade" id="modalDetalhesTransacao" tabindex="-1" role="dialog" aria-labelledby="modalDetalhesTransacaoLabel">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header" style="background-color: #5bc0de;">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close" style="color: white; opacity: 0.8;">
                    <span aria-hidden="true">&times;</span>
                </button>
                <h2 class="modal-title" id="modalDetalhesTransacaoLabel" style="color: white; font-size: 24px;">
                    <i class="fa fa-search"></i> Detalhes da Transação PIX
                </h2>
            </div>
            <div class="modal-body">
                <div id="loadingDetails" class="text-center">
                    <i class="fa fa-spinner fa-spin fa-3x"></i>
                    <p>Carregando detalhes...</p>
                </div>
                
                <div id="detalhesContent" style="display: none;">
                    <!-- Informações Gerais -->
                    <div class="row">
                        <div class="col-md-12">
                            <h4><i class="fa fa-info-circle"></i> Informações Gerais</h4>
                            <hr>
                            <div class="row">
                                <div class="col-md-6">
                                    <p><strong>ID da Transação:</strong> <span id="transaction_id_details"></span></p>
                                    <p><strong>Status:</strong> <span id="status_details"></span></p>
                                    <p><strong>Valor:</strong> R$ <span id="amount_details"></span></p>
                                </div>
                                <div class="col-md-6">
                                    <p><strong>Data de Criação:</strong> <span id="date_created_details"></span></p>
                                    <p><strong>Data de Pagamento:</strong> <span id="date_payment_details"></span></p>
                                    <p><strong>Data de Reembolso:</strong> <span id="date_refund_details"></span></p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Histórico de Reembolsos -->
                    <div class="row" style="margin-top: 20px;">
                        <div class="col-md-12">
                            <h4><i class="fa fa-history"></i> Histórico de Reembolsos</h4>
                            <hr>
                            <div id="refundHistoryEmpty" class="alert alert-info">
                                <i class="fa fa-info-circle"></i> Nenhum histórico de reembolso encontrado.
                            </div>
                            <div id="refundHistoryContent">
                                <table class="table table-striped table-bordered">
                                    <thead>
                                        <tr>
                                            <th>Data</th>
                                            <th>Ação</th>
                                            <th>Valor</th>
                                            <th>Detalhes</th>
                                        </tr>
                                    </thead>
                                    <tbody id="refundHistoryTable">
                                        <!-- Conteúdo do histórico de reembolsos -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Logs do Webhook -->
                    <div class="row" style="margin-top: 20px;">
                        <div class="col-md-12">
                            <h4><i class="fa fa-code"></i> Logs do Webhook</h4>
                            <hr>
                            <div id="webhookLogEmpty" class="alert alert-info">
                                <i class="fa fa-info-circle"></i> Nenhum log de webhook encontrado.
                            </div>
                            <pre id="webhookLogContent" class="pre-scrollable" style="max-height: 200px; background-color: #f5f5f5; padding: 10px; border-radius: 4px;"></pre>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<?php init_tail(); ?>

<!-- Estilos CSS personalizados para resolver problemas de interface -->
<style>
/* Melhorar visibilidade dos títulos de modal */
.modal-header h2, .modal-header h3, .modal-header h4 {
    color: white !important; 
    text-shadow: 0px 1px 2px rgba(0, 0, 0, 0.2);
}

/* Estilizar inputs radio e checkbox para melhor visibilidade */
input[type="radio"], input[type="checkbox"] {
    width: 18px !important;
    height: 18px !important;
    margin-right: 8px !important;
    position: relative !important;
    vertical-align: middle !important;
    visibility: visible !important;
    opacity: 1 !important;
}

input[type="radio"]:checked, input[type="checkbox"]:checked {
    background-color: #337ab7 !important;
    border-color: #2e6da4 !important;
}

/* Estilizar labels para inputs */
.radio label, .checkbox label {
    display: flex !important;
    align-items: center !important;
    padding-left: 0 !important;
    font-weight: normal !important;
    margin-bottom: 10px !important;
}

/* Melhorar visualização do campo de valor parcial */
#outroValorInput {
    font-size: 16px !important;
    height: 40px !important;
    font-weight: bold !important;
}

/* Garantir que o conteúdo do modal seja bem espaçado */
.modal-body {
    padding: 20px 25px !important;
}
</style>

<script>
$(document).ready(function() {
    // Inicializa o datepicker
    $('.datepicker').datepicker({
        format: 'dd/mm/yyyy',
        autoclose: true,
        todayHighlight: true,
        language: 'pt-BR'
    });
    
    // Inicializa tooltips
    $('[data-toggle="tooltip"]').tooltip();
    
    // Usar a função initDataTable do Perfex CRM para inicializar a tabela
    var tabelaTransacoes = initDataTable('.table-pix', admin_url + 'pagou/get_pix_transactions', [], [], {}, [0, 'desc']);
    
    // Limpar filtros
    $('#limpar-filtros').on('click', function() {
        $('#client').val('');
        $('#start_date').val('');
        $('#end_date').val('');
        $('#status').val('').selectpicker('refresh');
        $('#filtro-form').submit();
    });
    
    // Exibe/oculta o campo "Outro Valor" caso seja selecionado reembolso parcial
    // Atualize esta parte no código existente:
$('input[name="amount_type"]').on('change', function() {
    if ($('#outroValor').is(':checked')) {
        $('#outroValorInputGroup').show();
        $('#outroValorInput').prop('required', true).focus();
        
        // Iniciar com um valor vazio
        $('#outroValorInput').val('');
        $('#valorExibicao').text('R$ 0,00');
    } else {
        $('#outroValorInputGroup').hide();
        $('#outroValorInput').prop('required', false);
        
        // Ao selecionar reembolso total, atualiza o valor exibido para o valor total
        var valorTotal = $('#valorTotalSpan').text();
        $('#valorExibicao').text('R$ ' + valorTotal);
    }
});

$('#outroValorInput').on('focus', function() {
    // Se o campo estiver vazio, deixe-o vazio para o usuário digitar
    if ($(this).val() === '0,00' || $(this).val() === '') {
        $(this).val('');
    }
});
    
    // Atualiza o valor exibido quando o usuário digita um valor parcial
    $('#outroValorInput').on('input', function() {
        var valorDigitado = $(this).val();
        formatarInputMoeda(this);
        
        var valor = converterMoedaParaNumero(valorDigitado);
        if (!isNaN(valor) && valor > 0) {
            $('#valorExibicao').text('R$ ' + formatarMoeda(valor));
        } else {
            $('#valorExibicao').text('R$ 0,00');
        }
    });
    
    // Exibe/oculta o campo de texto para outros motivos
    $('#refundReasonSelect').on('change', function() {
        if ($(this).val() === 'Outro motivo') {
            $('#refundReason').show().prop('required', true).focus();
        } else {
            $('#refundReason').hide().prop('required', false);
        }
    });
    
    // Habilita "Confirmar Reembolso" somente após marcar confirmRefund
    $('#confirmRefund').on('change', function() {
        $('#btnConfirmarReembolso').prop('disabled', !this.checked);
    });
});

/**
 * Formata um número para o formato de moeda brasileira
 * @param {number} valor - Valor a ser formatado
 * @returns {string} - Valor formatado (ex: 1.234,56)
 */
function formatarMoeda(valor) {
    if (isNaN(valor)) return '0,00';
    return parseFloat(valor).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, ".");
}

/**
 * Converte uma string de moeda para número
 * @param {string} valor - Valor no formato brasileiro (ex: 1.234,56)
 * @returns {number} - Valor numérico (ex: 1234.56)
 */
function converterMoedaParaNumero(valor) {
    if (!valor) return 0;
    // Remove todos os pontos (separadores de milhar)
    valor = valor.replace(/\./g, '');
    // Substitui vírgula por ponto
    valor = valor.replace(',', '.');
    return parseFloat(valor);
}

/**
 * Formata o input de moeda enquanto o usuário digita
 * @param {HTMLElement} input - Elemento input a ser formatado
 */
function formatarInputMoeda(input) {
    // Guarda a posição do cursor
    var start = input.selectionStart;
    var end = input.selectionEnd;
    var valorAtual = input.value;
    
    // Remove caracteres não numéricos
    var valorSemFormato = valorAtual.replace(/\D/g, '');
    
    if (valorSemFormato === '') {
        input.value = '';
        return;
    }
    
    // Converte para número e formata
    var valorNumerico = parseFloat(valorSemFormato) / 100;
    var valorFormatado = valorNumerico.toFixed(2).replace('.', ',');
    
    // Adiciona separadores de milhar
    if (valorNumerico >= 1000) {
        valorFormatado = valorFormatado.replace(/\B(?=(\d{3})+(?!\d))/g, ".");
    }
    
    // Atualiza o valor
    input.value = valorFormatado;
    
    // Recalcula a posição do cursor, contando a diferença entre o valor formatado e não formatado
    var diferenca = valorFormatado.length - valorSemFormato.length;
    
    // Atualiza o valor exibido no destaque
    var valor = converterMoedaParaNumero(valorFormatado);
    $('#valorExibicao').text('R$ ' + formatarMoeda(valor));
}

/**
 * refundTransaction: abre modal e preenche dados do reembolso.
 * A requisição DELETE será feita via JavaScript para o controlador (Pagou.php),
 * que então chamará a API Pagou por cURL para realizar o reembolso.
 */
function refundTransaction(transactionId, clientName, invoiceNumber, amount) {
    console.log('Abrindo modal de reembolso para a transação PIX ID:', transactionId);
    
    // Preenche dados no modal
    $('#reembolsoCliente').text(clientName);
    $('#reembolsoInvoice').text(invoiceNumber);
    
    // Formata e preenche os valores
    var valorFormatado = formatarMoeda(parseFloat(amount));
    $('#valorTotalSpan').text(valorFormatado);
    $('#valorExibicao').text('R$ ' + valorFormatado);
    
    // Limpa campos
    $('#outroValorInput').val('');
    $('#refundReasonSelect').val('Cancelamento solicitado pelo cliente');
    $('#refundReason').val('').hide();
    $('#confirmRefund').prop('checked', false);
    $('#btnConfirmarReembolso').prop('disabled', true);
    $('#valorTotal').prop('checked', true);
    $('#outroValorInputGroup').hide();

    // Exibe o modal
    $('#modalReembolsoPix').modal('show');

    // Evento de click no botão "Confirmar Reembolso"
    $('#btnConfirmarReembolso').off('click').on('click', function() {
        // Define o valor do reembolso
        var refundAmount = amount;
        if ($('#outroValor').is(':checked')) {
            var valorParcial = converterMoedaParaNumero($('#outroValorInput').val());
            if (!valorParcial || isNaN(valorParcial) || valorParcial <= 0) {
                alert('Por favor, informe um valor válido para o reembolso parcial.');
                return;
            }
            
            if (valorParcial > amount) {
                alert('O valor do reembolso não pode ser maior que o valor total da transação.');
                return;
            }
            
            refundAmount = valorParcial;
        }
        
        // Define o motivo
        var reasonText = $('#refundReasonSelect').val();
        if (reasonText === 'Outro motivo') {
            var outroMotivo = $('#refundReason').val().trim();
            if (!outroMotivo) {
                alert('Por favor, especifique o motivo do reembolso.');
                return;
            }
            reasonText = outroMotivo;
        }
        
        // Confirmação final
        if (!confirm('Tem certeza que deseja reembolsar esta transação PIX?\n\nCliente: ' + clientName + '\nFatura: ' + invoiceNumber + '\nValor: R$ ' + formatarMoeda(refundAmount))) {
            return;
        }
        
        // Exibe loading
        $('#btnConfirmarReembolso').html('<i class="fa fa-spinner fa-spin"></i> Processando...').prop('disabled', true);
        
        // Faz requisição DELETE via fetch para o endpoint de reembolso
        let endpoint = admin_url + "pagou/reembolso_ajax?transaction_id=" + transactionId + "&payment_type=pix";
        let payload = {
            amount: refundAmount,
            description: reasonText,
            reason: 3  // 3 = devolução solicitada pelo cliente final
        };

        fetch(endpoint, {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                // Fecha o modal
                $('#modalReembolsoPix').modal('hide');
                
                // Exibe mensagem de sucesso
                alert(data.message || 'Estorno solicitado à Pagou.');
                
                // Recarrega apenas a tabela DataTable
                var table = $('.table-pix').DataTable();
                setTimeout(function() {
                    table.ajax.reload(null, false); // O false mantém a página atual
                }, 1000);
            } else if (data.status === 'warning') {
                // Fecha o modal
                $('#modalReembolsoPix').modal('hide');
                
                // Exibe mensagem de alerta
                alert(data.message);
                
                // Recarrega apenas a tabela DataTable
                var table = $('.table-pix').DataTable();
                setTimeout(function() {
                    table.ajax.reload(null, false);
                }, 1000);
            } else {
                // Restaura botão
                $('#btnConfirmarReembolso').html('<i class="fa fa-check"></i> Confirmar Reembolso').prop('disabled', false);
                
                // Exibe mensagem de erro
                alert('Erro ao reembolsar: ' + (data.message || 'Verifique logs.'));
            }
        })
        .catch(err => {
            // Restaura botão
            $('#btnConfirmarReembolso').html('<i class="fa fa-check"></i> Confirmar Reembolso').prop('disabled', false);
            
            // Exibe erro no console e alerta
            console.error('Erro ao chamar reembolso_ajax:', err);
            alert('Falha na requisição de reembolso PIX. Verifique sua conexão com o servidor.');
        });
    });
}

/**
 * Exibe detalhes da transação em um modal
 * @param {string} transactionId ID da transação
 */
function viewTransactionDetails(transactionId) {
    // Reseta modal
    $('#loadingDetails').show();
    $('#detalhesContent').hide();
    $('#webhookLogContent').html('');
    $('#refundHistoryTable').html('');
    
    // Exibe modal
    $('#modalDetalhesTransacao').modal('show');
    
    // Busca detalhes da transação
    $.ajax({
        url: admin_url + 'pagou/get_transaction_details',
        type: 'POST',
        data: {
            transaction_id: transactionId
        },
        dataType: 'json',
        success: function(response) {
            if (response.status === 'success') {
                // Preenche informações gerais
                $('#transaction_id_details').text(response.transaction.transaction_id);
                
                // Status com formatação
                var status = response.transaction.status;
                var statusHtml = '';
                
                if (status === 'paid' || status === '4') {
                    statusHtml = '<span class="label label-success"><i class="fa fa-check-circle"></i> Pago</span>';
                } else if (status === 'pending' || status === '1') {
                    statusHtml = '<span class="label label-warning"><i class="fa fa-clock-o"></i> Pendente</span>';
                } else if (status === 'refund_requested') {
                    statusHtml = '<span class=\"label label-info\">Estorno solicitado</span>';
                } else if (status === 'refunded') {
                    statusHtml = '<span class="label label-danger"><i class="fa fa-undo"></i> Reembolsado</span>';
                } else {
                    statusHtml = '<span class="label label-default">' + status + '</span>';
                }
                
                $('#status_details').html(statusHtml);
                $('#amount_details').text(response.formatted.amount_final);
                $('#date_created_details').text(response.formatted.date_created);
                $('#date_payment_details').text(response.formatted.data_pagamento);
                $('#date_refund_details').text(response.formatted.data_estorno || '---');
                
                // Histórico de reembolsos
                if (response.refund_history && response.refund_history.length > 0) {
                    $('#refundHistoryEmpty').hide();
                    $('#refundHistoryContent').show();
                    
                    var refundHtml = '';
                    response.refund_history.forEach(function(log) {
                        var acaoLabel = '';
                        var detalhes = '';
                        
                        // Formata ação
                        switch(log.action) {
                            case 'refund_requested':
                                acaoLabel = '<span class="label label-info">Solicitado</span>';
                                detalhes = 'Motivo: ' + log.description;
                                if (log.staff_name) {
                                    detalhes += '<br>Por: ' + log.staff_name;
                                }
                                break;
                            case 'refund_success':
                                acaoLabel = '<span class="label label-success">Aprovado</span>';
                                detalhes = 'HTTP: ' + log.http_code;
                                break;
                            case 'refund_failed':
                                acaoLabel = '<span class="label label-danger">Falha</span>';
                                detalhes = 'Erro: ' + log.api_error;
                                break;
                            case 'refund_webhook':
                                acaoLabel = '<span class="label label-primary">Webhook</span>';
                                detalhes = 'Notificação recebida';
                                break;
                            case 'update_status_failed':
                                acaoLabel = '<span class="label label-warning">Falha na Atualização</span>';
                                detalhes = log.details || '';
                                break;
                            default:
                                acaoLabel = '<span class="label label-default">' + log.action + '</span>';
                        }
                        
                        // Formata valor
                        var valorFormatado = log.amount ? formatarMoeda(parseFloat(log.amount)) : '---';
                        
                        refundHtml += '<tr>' +
                            '<td>' + (log.timestamp || '---') + '</td>' +
                            '<td>' + acaoLabel + '</td>' +
                            '<td>R$ ' + valorFormatado + '</td>' +
                            '<td>' + detalhes + '</td>' +
                        '</tr>';
                    });
                    
                    $('#refundHistoryTable').html(refundHtml);
                } else {
                    $('#refundHistoryEmpty').show();
                    $('#refundHistoryContent').hide();
                }
                
                // Logs do webhook
                if (response.webhook_logs && response.webhook_logs.length > 0) {
                    $('#webhookLogEmpty').hide();
                    
                    var logContent = '';
                    response.webhook_logs.forEach(function(log, index) {
                        if (index > 0) {
                            logContent += '\n\n------------------------------\n\n';
                        }
                        
                        if (typeof log === 'object') {
                            logContent += JSON.stringify(log, null, 2);
                        } else {
                            logContent += log;
                        }
                    });
                    
                    $('#webhookLogContent').text(logContent);
                } else {
                    $('#webhookLogEmpty').show();
                    $('#webhookLogContent').html('');
                }
                
                // Exibe conteúdo
                $('#loadingDetails').hide();
                $('#detalhesContent').show();
            } else {
                alert('Erro ao carregar detalhes: ' + response.message);
                $('#modalDetalhesTransacao').modal('hide');
            }
        },
        error: function() {
            alert('Erro ao carregar detalhes da transação. Tente novamente.');
            $('#modalDetalhesTransacao').modal('hide');
        }
    });
}
</script>