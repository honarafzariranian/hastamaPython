document.addEventListener('DOMContentLoaded', function () {
    var titleElement = document.getElementById('payrollPreviewTitle');
    var content = document.getElementById('payrollPreviewContent');
    var printButton = document.getElementById('payrollPrintButton');
    var downloadButton = document.getElementById('payrollDownloadButton');
    var data = null;

    try {
        data = JSON.parse(sessionStorage.getItem('payrollPreviewData') || 'null');
    } catch (error) {
        data = null;
    }

    if (!data || !Array.isArray(data.sections) || !data.sections.length) {
        content.innerHTML = '<div class="preview-empty">اطلاعاتی برای نمایش گزارش پیدا نشد. گزارش را از پنل حقوق دوباره باز کنید.</div>';
        printButton.disabled = true;
        downloadButton.disabled = true;
        return;
    }

    titleElement.textContent = data.title || 'گزارش حقوق و دستمزد';
    content.replaceChildren();

    data.sections.forEach(function (sectionData) {
        if (!sectionData || !sectionData.html) return;
        var section = document.createElement('section');
        section.className = 'preview-section';
        var heading = document.createElement('h2');
        heading.textContent = sectionData.heading || 'گزارش';
        section.appendChild(heading);

        var tableHolder = document.createElement('div');
        tableHolder.innerHTML = sectionData.html;
        section.appendChild(tableHolder.firstElementChild);
        content.appendChild(section);
    });

    printButton.addEventListener('click', function () {
        window.print();
    });

    downloadButton.addEventListener('click', async function () {
        downloadButton.disabled = true;
        var originalText = downloadButton.textContent;
        downloadButton.textContent = 'در حال آماده‌سازی…';
        try {
            var cssResponse = await fetch('/static/css/payroll-report-preview.css', { credentials: 'same-origin' });
            var css = cssResponse.ok ? await cssResponse.text() : '';
            var downloadableContent = content.cloneNode(true);
            var documentText = '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' +
                escapeHtml(data.title || 'گزارش حقوق و دستمزد') + '</title><style>' + css + '</style></head><body><main class="preview-page"><header class="preview-toolbar"><h1>' +
                escapeHtml(data.title || 'گزارش حقوق و دستمزد') + '</h1></header>' + downloadableContent.outerHTML + '</main></body></html>';
            var blob = new Blob([documentText], { type: 'text/html;charset=utf-8' });
            var url = URL.createObjectURL(blob);
            var link = document.createElement('a');
            link.href = url;
            link.download = 'گزارش-حقوق-' + (data.title || 'دوره') + '.html';
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
        } catch (error) {
            showSystemError('دریافت فایل گزارش انجام نشد. لطفاً دوباره تلاش کنید.');
        } finally {
            downloadButton.disabled = false;
            downloadButton.textContent = originalText;
        }
    });

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (character) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character];
        });
    }
});
