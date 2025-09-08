<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="container">
    <div class="row">
        <div class="col-md-8 col-md-offset-2">
            <div class="panel_s">
                <div class="panel-body">
                    <div class="row">
                        <div class="col-md-12">
                            <h4 class="no-margin">
                                <?php echo _l('invoice'); ?> - <?php echo format_invoice_number($invoice->id); ?>
                            </h4>
                            <hr />
                            <?php if (is_invoice_overdue($invoice)) { ?>
                                <div class="alert alert-danger">
                                    <?php echo _l('overdue_by_days', get_total_days_overdue($invoice->duedate)); ?>
                                </div>
                            <?php } ?>
                        </div>
                        <div class="col-md-12">
                            <h4 class="text-center">Selecione a forma de pagamento</h4>
                            <div class="row">
                                <?php if ($payment_methods['boleto']) { ?>
                                <div class="col-md-6">
                                    <a href="<?php echo site_url('pagou/checkout/boleto/' . $invoice->hash); ?>" class="btn btn-info btn-block btn-lg">
                                        <i class="fa fa-barcode fa-fw fa-lg"></i> Pagar com Boleto
                                    </a>
                                </div>
                                <?php } ?>
                                <?php if ($payment_methods['pix']) { ?>
                                <div class="col-md-6">
                                    <a href="<?php echo site_url('pagou/checkout/pix/' . $invoice->hash); ?>" class="btn btn-success btn-block btn-lg">
                                        <i class="fa fa-qrcode fa-fw fa-lg"></i> Pagar com PIX
                                    </a>
                                </div>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>