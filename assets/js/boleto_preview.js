function initBoletoPreview() {
    const boletoObject = document.getElementById('boleto-object');
    const previewLoader = document.getElementById('preview-loader');
    const container = document.getElementById('boleto-container');

    boletoObject.onload = function() {
        previewLoader.style.display = 'none';
        container.style.height = '800px';
    };

    boletoObject.onerror = function() {
        previewLoader.innerHTML = `
            <i class="fa fa-exclamation-circle"></i>
            <span>Erro ao carregar o boleto. 
                <a href="${boletoUrl}" target="_blank">Clique aqui para abrir em nova aba</a>
            </span>
        `;
    };
}

function printBoletoOnly() {
    const printWindow = window.open(boletoUrl, '_blank');
    
    if (printWindow) {
        printWindow.onload = function() {
            setTimeout(function() {
                printWindow.print();
                const checkPrintDialog = setInterval(function() {
                    if (printWindow.document.readyState === 'complete') {
                        clearInterval(checkPrintDialog);
                        printWindow.close();
                    }
                }, 1000);
            }, 1500);
        };
    } else {
        alert('Seu navegador bloqueou o pop-up. Por favor, permita pop-ups para imprimir o boleto.');
    }
}

function handleShare() {
    if (navigator.share) {
        navigator.share({
            title: 'Boleto de Pagamento',
            text: 'Aqui está o boleto para pagamento da fatura.',
            url: boletoUrl
        }).catch(console.error);
    } else {
        copyToClipboard(boletoUrl);
        alert('O link do boleto foi copiado para a área de transferência.');
    }
}

function copyToClipboard(text) {
    const tempInput = document.createElement('input');
    tempInput.style = 'position: absolute; left: -1000px; top: -1000px';
    tempInput.value = text;
    document.body.appendChild(tempInput);
    tempInput.select();
    document.execCommand('copy');
    document.body.removeChild(tempInput);
}

function initScrollArrow() {
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

    scrollArrow.addEventListener('click', () => {
        window.scrollTo({
            top: document.documentElement.scrollHeight,
            behavior: 'smooth'
        });
    });
}

function checkPaymentStatus() {
    jQuery.get(checkStatusUrl)
        .done(function(response) {
            if (response.status === 'paid') {
                location.reload();
            }
        });
}

// Initialize everything when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    initBoletoPreview();
    initScrollArrow();
    
    // Add event listeners
    document.getElementById('shareButton').addEventListener('click', handleShare);
    
    // Check payment status every 5 minutes
    setInterval(checkPaymentStatus, 300000);
});