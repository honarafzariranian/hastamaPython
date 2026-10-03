<?php

namespace App\Http\Controllers\Admin;

use App\Support\Legacy\LegacyReportPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;

/**
 * The printable report documents — the five `*_report_page` routes and
 * `GET /download_pdf` from `app/main.py`.
 *
 * Each report page guards with `_require_auth` and renders one Jinja template
 * from `app/templates` as `text/html`; the port returns the same document
 * from {@see LegacyReportPage}, which reproduces the template the running
 * server rendered (the `{{ url_for('static', …) }}` calls included).
 *
 * `download_pdf` is the one route that does not return a page: it converts the
 * final report to PDF with `pdfkit`.  **Its template, `finalReportUser.html`,
 * is missing from this installation** — it is not in `app/templates` and not
 * in `docs/migration/TEMPLATE_INVENTORY.md` — so the Python answers 503 before
 * reaching pdfkit:
 *
 * ```python
 * # app/main.py:5491-5498
 * try:
 *     template = templates.get_template('finalReportUser.html')
 * except Exception:
 *     return JSONResponse(status_code=503, content={
 *         "success": False, "message": "قالب گزارش نهایی در این نصب موجود نیست."})
 * ```
 *
 * The port reproduces that 503.  The pipeline behind it — render the template,
 * inline `finalReportUserPrint.css` before `</head>`, write the result to a
 * private temporary file, convert with `pdfkit.from_file` hardened against
 * CVE-2025-26240 (`disable-local-file-access`, `disable-javascript`, no
 * `--user-style-sheet`), and stream the bytes as
 * `application/pdf; attachment; filename=final_report.pdf`
 * (`app/main.py:5501-5554`) — has no PHP equivalent in this project: no PDF
 * library is a dependency, and adding one needs approval.  It is unreachable
 * in this installation anyway, because the template it would render does not
 * exist.  It is documented here rather than stubbed.
 */
final class ReportPageController extends AdminPanelController
{
    /**
     * `GET /leave_report_page` — the individual leave report.
     */
    public function leave(Request $request)
    {
        return $this->renderReport($request, LegacyReportPage::leave());
    }

    /**
     * `GET /hourlypass_Report_page` — the individual hourly-pass report.
     */
    public function hourlyPass(Request $request)
    {
        return $this->renderReport($request, LegacyReportPage::hourlyPass());
    }

    /**
     * `GET /overtime_report_page` — the individual overtime report.
     */
    public function overtime(Request $request)
    {
        return $this->renderReport($request, LegacyReportPage::overtime());
    }

    /**
     * `GET /payroll_report_page` — the payroll calculation preview.
     */
    public function payroll(Request $request)
    {
        return $this->renderReport($request, LegacyReportPage::payroll());
    }

    /**
     * `GET /final_report_page` — the final attendance report.
     *
     * The only page with dynamic content: its title carries the current Jalali
     * month name and year, which the handler computes from
     * `JalaliDate.today()` (`app/main.py:5456-5458`).
     */
    public function final(Request $request)
    {
        return $this->renderReport($request, LegacyReportPage::final());
    }

    /**
     * `GET /download_pdf` — the final report as a PDF attachment.
     *
     * Reproduces the 503 the running server answers here, because the template
     * the pipeline renders is missing from this installation (see the class
     * docblock).
     */
    public function downloadPdf(Request $request): JsonResponse
    {
        $authError = $this->requireAuth($request);

        if ($authError !== null) {
            return $authError;
        }

        if (! View::exists('finalReportUser')) {
            return response()->json([
                'success' => false,
                'message' => 'قالب گزارش نهایی در این نصب موجود نیست.',
            ], 503);
        }

        // Unreachable in this installation — see the class docblock.  Kept as a
        // guard so a future `finalReportUser` template cannot be silently
        // served as a PDF this project cannot produce.
        throw new \RuntimeException('The final report PDF template is missing in this installation.');
    }

    /**
     * `_require_auth`, then the rendered document.
     *
     * @return Response
     */
    private function renderReport(Request $request, string $html)
    {
        $authError = $this->requireAuth($request);

        if ($authError !== null) {
            return $authError;
        }

        return LegacyReportPage::response($html);
    }
}
