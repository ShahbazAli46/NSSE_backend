<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class DocumentPdfService
{
    protected ?string $fontBase64 = null;
    protected ?string $logoBase64 = null;

    /**
     * Get or cached base64 Nastaleeq font data URI.
     */
    public function getNastaleeqFontBase64(): string
    {
        if ($this->fontBase64 !== null) {
            return $this->fontBase64;
        }

        $fontPath = resource_path('fonts/jameel-noori-nastaleeq.ttf');
        if (!File::exists($fontPath)) {
            // Fallback root workspace path
            $fontPath = base_path('../jameel-noori-nastaleeq.ttf');
        }

        if (File::exists($fontPath)) {
            $content = File::get($fontPath);
            $this->fontBase64 = 'data:font/truetype;charset=utf-8;base64,' . base64_encode($content);
        } else {
            $this->fontBase64 = '';
        }

        return $this->fontBase64;
    }

    /**
     * Get or cached base64 logo data URI.
     */
    public function getLogoBase64(): string
    {
        if ($this->logoBase64 !== null) {
            return $this->logoBase64;
        }

        $logoPath = public_path('logo.png');
        if (!File::exists($logoPath)) {
            $logoPath = base_path('../logo.png');
        }

        if (File::exists($logoPath)) {
            $content = File::get($logoPath);
            $this->logoBase64 = 'data:image/png;base64,' . base64_encode($content);
        } else {
            $this->logoBase64 = '';
        }

        return $this->logoBase64;
    }

    /**
     * Auto-detect Chrome executable on macOS or Linux.
     */
    public function getChromeBinaryPath(): string
    {
        $candidates = [
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            '/Applications/Chromium.app/Contents/MacOS/Chromium',
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
        ];

        foreach ($candidates as $path) {
            if (File::exists($path) && is_executable($path)) {
                return $path;
            }
        }

        // Try 'which' command fallback
        $process = new Process(['which', 'google-chrome']);
        $process->run();
        if ($process->isSuccessful() && !empty(trim($process->getOutput()))) {
            return trim($process->getOutput());
        }

        throw new Exception("Chrome binary not found for PDF rendering. Please install Google Chrome or Chromium.");
    }

    /**
     * Render a Blade view with data and compile to PDF file.
     */
    public function generateFromView(string $viewName, array $data = [], ?string $outputPath = null): string
    {
        // Inject shared assets if not explicitly passed
        if (!isset($data['font_base64'])) {
            $data['font_base64'] = $this->getNastaleeqFontBase64();
        }
        if (!isset($data['logo_base64'])) {
            $data['logo_base64'] = $this->getLogoBase64();
        }

        $html = view($viewName, $data)->render();

        return $this->generateFromHtml($html, $outputPath);
    }

    /**
     * Check if remote bridge should be used (e.g. on Hostinger Shared Hosting).
     */
    public function shouldUseRemoteBridge(): bool
    {
        $forcedDriver = config('services.pdf_bridge.driver', env('PDF_DRIVER', 'auto'));
        if ($forcedDriver === 'remote') {
            return true;
        }

        $endpoint = config('services.pdf_bridge.endpoint', env('PDF_BRIDGE_ENDPOINT'));
        if (!empty($endpoint) && $forcedDriver !== 'local') {
            return true;
        }

        return false;
    }

    /**
     * Compile HTML string into high-resolution A4 PDF via Local Chrome or Remote Bridge.
     */
    public function generateFromHtml(string $html, ?string $outputPath = null): string
    {
        // 1. If remote serverless bridge is configured (e.g. for Hostinger Shared Hosting)
        if ($this->shouldUseRemoteBridge()) {
            return $this->generateViaRemoteBridge($html, $outputPath);
        }

        // 2. Otherwise generate via local Headless Chrome binary
        try {
            return $this->generateViaLocalChrome($html, $outputPath);
        } catch (Exception $e) {
            // If local Chrome fails or isn't installed, attempt remote bridge if endpoint exists
            $endpoint = config('services.pdf_bridge.endpoint', env('PDF_BRIDGE_ENDPOINT'));
            if (!empty($endpoint)) {
                return $this->generateViaRemoteBridge($html, $outputPath);
            }
            throw $e;
        }
    }

    /**
     * Render PDF using Local Headless Chrome CLI.
     */
    protected function generateViaLocalChrome(string $html, ?string $outputPath = null): string
    {
        $chromeBinary = $this->getChromeBinaryPath();

        // Create temporary directory for building PDF
        $tempDir = storage_path('app/pdf_temp');
        if (!File::isDirectory($tempDir)) {
            File::makeDirectory($tempDir, 0755, true);
        }

        $tempHtmlFile = $tempDir . '/doc_' . uniqid() . '.html';
        File::put($tempHtmlFile, $html);

        if ($outputPath === null) {
            $outputPath = storage_path('app/pdf_output/doc_' . uniqid() . '.pdf');
            $outputDir = dirname($outputPath);
            if (!File::isDirectory($outputDir)) {
                File::makeDirectory($outputDir, 0755, true);
            }
        }

        // Executive Institutional Dynamic Footer with real Page X of Y
        $footerTemplate = '<div style="font-size: 8.5px; width: 100%; display: flex; justify-content: space-between; align-items: center; padding: 4px 34px 4px 34px; color: #64748B; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; border-top: 1.5px solid #C5A059; border-bottom: 4px solid #062E1C; background: #F8FAFC; box-sizing: border-box;">'
            . '<div style="direction: rtl; font-size: 8.5px;">📍 چونیاں کیمپس، ضلع قصور، پنجاب (Chunian Campus, Kasur, Punjab)</div>'
            . '<div style="font-weight: 700; color: #0B462C; direction: rtl; font-size: 9px;">صفحہ <span class="pageNumber"></span> از <span class="totalPages"></span></div>'
            . '</div>';

        // Execute Headless Chrome Print-To-PDF command with pure HTML page-based rendering
        $cmd = [
            $chromeBinary,
            '--headless=new',
            '--disable-gpu',
            '--no-pdf-header-footer',
            '--run-all-compositor-stages-before-draw',
            '--no-sandbox',
            "--print-to-pdf={$outputPath}",
            $tempHtmlFile,
        ];

        $process = new Process($cmd);
        $process->setTimeout(30);
        $process->run();

        // Cleanup temporary HTML file
        if (File::exists($tempHtmlFile)) {
            File::delete($tempHtmlFile);
        }

        if (!$process->isSuccessful() || !File::exists($outputPath)) {
            throw new Exception("PDF compilation failed: " . $process->getErrorOutput() . " Output: " . $process->getOutput());
        }

        return $outputPath;
    }

    /**
     * Render PDF using Remote Serverless Chromium Bridge (for Shared Hosting environments).
     */
    protected function generateViaRemoteBridge(string $html, ?string $outputPath = null): string
    {
        $endpoint = config('services.pdf_bridge.endpoint', env('PDF_BRIDGE_ENDPOINT'));
        $apiKey = config('services.pdf_bridge.api_key', env('PDF_BRIDGE_API_KEY'));

        if (empty($endpoint)) {
            throw new Exception("PDF Bridge endpoint is not configured. For shared hosting deployment, please set PDF_BRIDGE_ENDPOINT in your .env file.");
        }

        $footerTemplate = '<div style="font-size: 8.5px; width: 100%; display: flex; justify-content: space-between; align-items: center; padding: 4px 34px 4px 34px; color: #64748B; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; border-top: 1.5px solid #C5A059; border-bottom: 4px solid #062E1C; background: #F8FAFC; box-sizing: border-box;">'
            . '<div style="direction: rtl; font-size: 8.5px;">📍 چونیاں کیمپس، ضلع قصور، پنجاب (Chunian Campus, Kasur, Punjab)</div>'
            . '<div style="font-weight: 700; color: #0B462C; direction: rtl; font-size: 9px;">صفحہ <span class="pageNumber"></span> از <span class="totalPages"></span></div>'
            . '</div>';

        $headers = [
            'Accept' => 'application/pdf',
        ];
        if (!empty($apiKey)) {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        $response = \Illuminate\Support\Facades\Http::withHeaders($headers)
            ->timeout(45)
            ->post($endpoint, [
                'html' => $html,
                'headerTemplate' => '<span></span>',
                'footerTemplate' => $footerTemplate,
                'displayHeaderFooter' => true,
                'printBackground' => true,
                'landscape' => false,
                'preferCSSPageSize' => true,
            ]);

        if (!$response->successful()) {
            throw new Exception("Remote Chromium Bridge failed (" . $response->status() . "): " . $response->body());
        }

        if ($outputPath === null) {
            $outputPath = storage_path('app/pdf_output/doc_' . uniqid() . '.pdf');
            $outputDir = dirname($outputPath);
            if (!File::isDirectory($outputDir)) {
                File::makeDirectory($outputDir, 0755, true);
            }
        }

        File::put($outputPath, $response->body());

        return $outputPath;
    }

    /**
     * Download helper for controllers.
     */
    public function download(string $viewName, array $data = [], string $filename = 'document.pdf')
    {
        $pdfPath = $this->generateFromView($viewName, $data);
        return response()->download($pdfPath, $filename, [
            'Content-Type' => 'application/pdf',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Inline stream helper for browser preview.
     */
    public function stream(string $viewName, array $data = [], string $filename = 'document.pdf')
    {
        $pdfPath = $this->generateFromView($viewName, $data);
        return response()->file($pdfPath, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }
}
