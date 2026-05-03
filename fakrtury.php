<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Mpdf\Mpdf;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

define('CONFIG_FILE', __DIR__ . '/config.json');
define('INVOICE_DIR', __DIR__ . '/invoices');

if (!is_dir(INVOICE_DIR)) {
    mkdir(INVOICE_DIR, 0777, true);
}

class AppConfig
{
    public static function load(): array
    {
        if (file_exists(CONFIG_FILE)) {
            $data = json_decode(file_get_contents(CONFIG_FILE), true);
            return is_array($data) ? $data : [];
        }
        return [];
    }

    public static function save(array $data): void
    {
        $cfg = self::load();
        $keys = ['d_nazev', 'd_ico', 'd_dic', 'd_adresa', 'b_ucet', 'b_iban', 'b_nazev', 'e_server', 'e_login', 'logo_base64', 'f_poznamka'];
        foreach ($keys as $k) {
            if (isset($data[$k])) {
                $cfg[$k] = $data[$k];
            }
        }
        file_put_contents(CONFIG_FILE, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}

class AresService
{
    public static function fetch(string $ico): array
    {
        $cleanIco = preg_replace('/\D/', '', $ico);
        if (empty($cleanIco)) {
            throw new RuntimeException("Zadejte platné IČO.");
        }

        $url = "https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/" . str_pad($cleanIco, 8, '0', STR_PAD_LEFT);
        
        $options = [
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/json\r\n" .
                            "Content-Type: application/json\r\n" .
                            "User-Agent: SaaS-Invoicing/1.0\r\n",
                'timeout' => 10,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ];
        
        $context = stream_context_create($options);
        $res = @file_get_contents($url, false, $context);
        
        if ($res === false) {
            $error = error_get_last();
            throw new RuntimeException("Chyba API ARES: " . ($error['message'] ?? 'Nelze navázat spojení.'));
        }
        
        $code = 200;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $matches)) {
            $code = (int)$matches[1];
        }
        
        if ($code === 404) {
            throw new RuntimeException("IČO {$cleanIco} nebylo v registru ARES nalezeno.");
        }
        if ($code !== 200) {
            throw new RuntimeException("Chyba serveru ARES (Kód $code).");
        }
        
        $data = json_decode((string)$res, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException("Neplatná odpověď z registru.");
        }

        $ulice = $data['sidlo']['nazevUlice'] ?? $data['sidlo']['nazevObce'] ?? '';
        $cislo = $data['sidlo']['cisloDomovni'] ?? '';
        if (!empty($data['sidlo']['cisloOrientacni'])) {
            $cislo .= '/' . $data['sidlo']['cisloOrientacni'];
        }
        $psc = $data['sidlo']['psc'] ?? '';
        $obec = $data['sidlo']['nazevObce'] ?? '';
        
        return [
            'nazev' => $data['obchodniJmeno'] ?? '',
            'dic' => $data['dic'] ?? '',
            'adresa' => trim("$ulice $cislo, $psc $obec")
        ];
    }
}

class InvoiceGenerator
{
    public static function createPdf(array $data): string
    {
        $mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_top' => 15, 'margin_bottom' => 15, 'margin_left' => 20, 'margin_right' => 20]);
        $mpdf->shrink_tables_to_fit = 0; // Striktní zamezení deformace tabulek

        $total = 0.0;
        $itemsHtml = '';
        foreach ($data['items'] as $item) {
            $qty = (int)($item['qty'] ?? 1);
            $price = (float)($item['price'] ?? 0);
            $rowTotal = $qty * $price;
            $total += $rowTotal;
            
            // Ošetření názvu: HTML entity -> nucené zalomení po 55 znacích -> převod \n na <br>
            $desc = htmlspecialchars($item['desc'] ?? '');
            $desc = wordwrap($desc, 55, "\n", true);
            $desc = nl2br($desc);

            $itemsHtml .= '<tr>
                <td style="padding: 10px 5px; border-bottom: 1px solid #eee; vertical-align: top;">' . $desc . '</td>
                <td style="padding: 10px 5px; border-bottom: 1px solid #eee; text-align: center; vertical-align: top;">' . $qty . '</td>
                <td style="padding: 10px 5px; border-bottom: 1px solid #eee; text-align: right; vertical-align: top;">' . number_format($price, 2, '.', ' ') . '</td>
                <td style="padding: 10px 5px; border-bottom: 1px solid #eee; text-align: right; vertical-align: top;">' . number_format($rowTotal, 2, '.', ' ') . '</td>
            </tr>';
        }

        $dphText = !empty($data['d_platce_dph']) ? 'Plátce DPH.' : 'Nejsem plátce DPH.';
        
        $tempFiles = [];

        // 1. Logo
        $logoHtml = '<div style="background-color: #3b82f6; color: white; width: 65px; height: 65px; text-align: center; font-size: 24px; font-weight: bold; border-radius: 8px; line-height: 65px;">LOGO</div>';
        if (!empty($data['logo_base64'])) {
            $b64string = preg_replace('#^data:image/[^;]+;base64,#', '', $data['logo_base64']);
            $logoPath = INVOICE_DIR . '/tmp_logo_' . uniqid() . '.png';
            if (file_put_contents($logoPath, base64_decode($b64string))) {
                $tempFiles[] = $logoPath;
                $logoHtml = '<img src="' . $logoPath . '" style="max-height: 70px; max-width: 200px;">';
            }
        }

        // 2. QR kód
        $qrHtml = '';
        $iban = str_replace(' ', '', $data['b_iban'] ?? '');
        if (!empty($iban)) {
            $vs = preg_replace('/[^0-9]/', '', $data['f_vs'] ?? '');
            $amount = number_format($total, 2, '.', '');
            $spd = "SPD*1.0*ACC:{$iban}*AM:{$amount}*CC:CZK" . ($vs ? "*X-VS:{$vs}" : "");
            
            $options = new QROptions([
                'outputType' => QRCode::OUTPUT_IMAGE_PNG,
                'eccLevel' => QRCode::ECC_M,
                'scale' => 4,
            ]);
            
            $qrPath = INVOICE_DIR . '/tmp_qr_' . uniqid() . '.png';
            (new QRCode($options))->render($spd, $qrPath);
            
            if (file_exists($qrPath)) {
                $tempFiles[] = $qrPath;
                $qrHtml = '<img src="' . $qrPath . '" width="95">';
            }
        }

        // 3. Poznámka
        $poznamkaHtml = '';
        $rawPoznamka = trim($data['f_poznamka'] ?? '');
        if (!empty($rawPoznamka)) {
            $safePoznamka = htmlspecialchars($rawPoznamka);
            $safePoznamka = wordwrap($safePoznamka, 110, "\n", true);
            $poznamkaHtml = '<div style="margin-bottom: 25px; font-size: 11px; line-height: 1.5; padding: 10px; background-color: #f9fafb; border: 1px solid #e0e0e0; border-radius: 4px;">
                                <strong>Poznámka:</strong><br>' . nl2br($safePoznamka) . '
                             </div>';
        }

        $css = '
        <style>
            body { font-family: "Helvetica", "Arial", sans-serif; font-size: 11px; color: #111827; }
            .header-line { border-bottom: 1px solid #e5e7eb; margin-bottom: 20px; margin-top: 15px; }
            .section-title { font-weight: bold; font-size: 12px; margin-bottom: 5px; color: #374151; text-transform: uppercase; }
            .items-table { width: 100%; border-collapse: collapse; margin-top: 25px; margin-bottom: 30px; table-layout: fixed; }
            .items-table th { background-color: #f3f4f6; padding: 10px 5px; text-align: left; font-weight: bold; font-size: 11px; border-top: 1px solid #e5e7eb; border-bottom: 1px solid #e5e7eb; color: #4b5563; }
            .footer-box { border: 1px solid #d1d5db; width: 100%; page-break-inside: avoid; border-radius: 4px; background-color: #ffffff; }
        </style>';
        $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
        
        $htmlHeader = '
        <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td width="50%" valign="top">' . $logoHtml . '</td>
                <td width="50%" align="right" valign="top">
                    <h1 style="font-size: 28px; font-weight: 900; margin: 0; letter-spacing: 1px; color: #111827;">FAKTURA</h1>
                    <div style="font-size: 11px; margin-top: 5px; color: #4b5563;">Číslo dokladu: <strong>' . htmlspecialchars($data['f_cislo'] ?? '') . '</strong></div>
                </td>
            </tr>
        </table>
        <div class="header-line"></div>';
        $mpdf->WriteHTML($htmlHeader, \Mpdf\HTMLParserMode::HTML_BODY);
        
        $htmlAddresses = '
        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 20px; table-layout: fixed;">
            <tr>
                <td width="50%" valign="top" style="padding-right: 20px; line-height: 1.5;">
                    <div class="section-title">DODAVATEL:</div>
                    <strong>' . htmlspecialchars($data['d_nazev'] ?? '') . '</strong><br>
                    ' . htmlspecialchars($data['d_adresa'] ?? '') . '<br>
                    IČO: ' . htmlspecialchars($data['d_ico'] ?? '') . '<br>
                    DIČ: ' . htmlspecialchars($data['d_dic'] ?? '') . '<br><br>
                    <span style="font-size: 10px; color: #6b7280;">' . $dphText . '</span>
                </td>
                <td width="50%" valign="top" style="line-height: 1.5;">
                    <div class="section-title">ODBĚRATEL:</div>
                    <strong>' . htmlspecialchars($data['o_nazev'] ?? '') . '</strong><br>
                    ' . htmlspecialchars($data['o_adresa'] ?? '') . '<br>
                    IČO: ' . htmlspecialchars($data['o_ico'] ?? '') . '<br>
                    DIČ: ' . htmlspecialchars($data['o_dic'] ?? '') . '<br><br>
                    
                    Vystaveno: ' . htmlspecialchars($data['f_vystaveno'] ?? '') . '<br>
                    <span style="font-size: 12px;">Splatnost: <strong>' . htmlspecialchars($data['f_splatnost'] ?? '') . '</strong></span>
                </td>
            </tr>
        </table>';
        $mpdf->WriteHTML($htmlAddresses, \Mpdf\HTMLParserMode::HTML_BODY);
        
        $htmlItems = '
        <table class="items-table">
            <thead>
                <tr>
                    <th width="50%">Položka</th>
                    <th width="15%" style="text-align: center;">Množství</th>
                    <th width="15%" style="text-align: right;">Cena/ks (Kč)</th>
                    <th width="20%" style="text-align: right;">Celkem (Kč)</th>
                </tr>
            </thead>
            <tbody>
                ' . $itemsHtml . '
            </tbody>
        </table>';
        $mpdf->WriteHTML($htmlItems, \Mpdf\HTMLParserMode::HTML_BODY);
        
        $htmlFooter = $poznamkaHtml . '
        <table class="footer-box" cellpadding="15" cellspacing="0">
            <tr>
                <td width="55%" valign="top" style="line-height: 1.6; font-size: 11px;">
                    <div class="section-title" style="margin-bottom: 8px;">PLATEBNÍ ÚDAJE:</div>
                    Banka: <strong>' . htmlspecialchars($data['b_nazev'] ?? '') . '</strong><br>
                    Účet: <strong>' . htmlspecialchars($data['b_ucet'] ?? '') . '</strong><br>
                    Variabilní symbol: <strong>' . htmlspecialchars($data['f_vs'] ?? '') . '</strong><br>
                    IBAN: ' . htmlspecialchars($data['b_iban'] ?? '') . '
                </td>
                <td width="45%" valign="top" align="right">
                    <div style="font-size: 12px; color: #4b5563; margin-bottom: 4px;">CELKEM K ÚHRADĚ</div>
                    <div style="font-size: 20px; font-weight: bold; margin-bottom: 10px; color: #111827;">' . number_format($total, 2, '.', ' ') . ' Kč</div>
                    ' . $qrHtml . '
                </td>
            </tr>
        </table>';
        $mpdf->WriteHTML($htmlFooter, \Mpdf\HTMLParserMode::HTML_BODY);
        
        $filename = 'Faktura_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $data['f_cislo'] ?? '1') . '_' . time() . '.pdf';
        $filepath = INVOICE_DIR . '/' . $filename;
        $mpdf->Output($filepath, \Mpdf\Output\Destination::FILE);
        
        foreach ($tempFiles as $tmp) {
            if (file_exists($tmp)) {
                unlink($tmp);
            }
        }
        
        return $filepath;
    }
}

// --- API ROUTING ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'ares') {
    header('Content-Type: application/json');
    try {
        echo json_encode(['success' => true, 'data' => AresService::fetch($_GET['ico'] ?? '')]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Neplatný formát dat.']);
        exit;
    }

    try {
        AppConfig::save($input);
        $pdfPath = InvoiceGenerator::createPdf($input);
        
        $emailSent = false;
        if (!empty($input['send_email']) && !empty($input['o_email'])) {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $input['e_server'] ?? '';
            $mail->SMTPAuth = true;
            $mail->Username = $input['e_login'] ?? '';
            $mail->Password = $input['e_heslo'] ?? '';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = 465;
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($input['e_login'] ?? '', $input['d_nazev'] ?? 'Fakturace');
            $mail->addAddress($input['o_email']);
            $mail->Subject = 'Faktura č. ' . ($input['f_cislo'] ?? '');
            $mail->Body = "Dobrý den,\n\nv příloze Vám zasíláme novou fakturu.\n\nS pozdravem,\n" . ($input['d_nazev'] ?? '');
            $mail->addAttachment($pdfPath);
            $mail->send();
            $emailSent = true;
        }

        echo json_encode([
            'success' => true, 
            'pdf_url' => 'invoices/' . basename($pdfPath),
            'email_sent' => $emailSent
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// --- FRONTEND UI ---
$config = AppConfig::load();
?>
<!DOCTYPE html>
<html lang="cs" class="scroll-smooth bg-slate-50 text-slate-900 font-sans">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generátor Faktur SaaS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { padding-bottom: 140px; }
        .a4-wrapper { 
            max-width: 210mm; /* A4 width */
            min-height: 297mm; /* A4 height */
            margin: 2rem auto; 
            background: #ffffff; 
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01); 
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 40px 50px; 
            position: relative;
        }
        .form-input { 
            display: block;
            width: 100%;
            border: 1px solid #cbd5e1;
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            line-height: 1.25rem;
            border-radius: 0.375rem;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.02);
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }
        .form-input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); }
        .form-label { display: block; font-size: 0.75rem; font-weight: 600; color: #64748b; margin-bottom: 0.25rem; text-transform: uppercase; letter-spacing: 0.025em; }
        .sticky-bar {
            position: fixed; bottom: 0; left: 0; right: 0; 
            background: rgba(255, 255, 255, 0.98); 
            backdrop-filter: blur(8px);
            border-top: 1px solid #e2e8f0; 
            box-shadow: 0 -4px 6px -1px rgba(0, 0, 0, 0.05);
            z-index: 50; padding: 1rem 0;
        }
    </style>
</head>
<body>

    <div class="a4-wrapper">
        
        <div class="flex justify-between items-start mb-12 border-b border-slate-100 pb-8">
            <div class="w-1/2">
                <label class="form-label">Nahrát logo (PNG/JPG)</label>
                <input type="file" accept="image/*" class="text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 cursor-pointer transition" onchange="encodeLogo(this)">
                <input type="hidden" id="logo_base64" value="<?= htmlspecialchars($config['logo_base64'] ?? '', ENT_QUOTES) ?>">
                <img id="logo_preview" src="<?= htmlspecialchars($config['logo_base64'] ?? '', ENT_QUOTES) ?>" class="mt-4 rounded border border-slate-200" style="max-height: 65px; display: <?= empty($config['logo_base64']) ? 'none' : 'block' ?>;">
            </div>
            <div class="w-1/2 text-right">
                <h1 class="text-3xl font-extrabold tracking-tight text-slate-900 mb-2">FAKTURA</h1>
                <div class="inline-block mt-2">
                    <label class="form-label text-left">Číslo dokladu</label>
                    <input type="text" id="f_cislo" placeholder="2026001" class="form-input text-lg font-bold text-right py-1">
                </div>
            </div>
        </div>
        
        <div class="grid grid-cols-2 gap-12 mb-10">
            <!-- Dodavatel -->
            <div>
                <h2 class="text-lg font-bold text-slate-800 mb-4 border-b border-slate-200 pb-2">Dodavatel</h2>
                <div class="space-y-4">
                    <div>
                        <label class="form-label">IČO</label>
                        <div class="flex rounded-md shadow-sm">
                            <input type="text" id="d_ico" value="<?= htmlspecialchars($config['d_ico'] ?? '', ENT_QUOTES) ?>" class="form-input rounded-none rounded-l-md border-r-0 focus:z-10" placeholder="Např. 00000000">
                            <button type="button" onclick="fetchAres('d')" class="inline-flex items-center px-4 py-2 border border-slate-300 text-sm font-medium rounded-r-md text-slate-700 bg-slate-50 hover:bg-slate-100 focus:outline-none focus:ring-1 focus:ring-blue-500 transition">ARES</button>
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Název firmy</label>
                        <input type="text" id="d_nazev" value="<?= htmlspecialchars($config['d_nazev'] ?? '', ENT_QUOTES) ?>" class="form-input font-semibold">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="form-label">DIČ</label>
                            <input type="text" id="d_dic" value="<?= htmlspecialchars($config['d_dic'] ?? '', ENT_QUOTES) ?>" class="form-input">
                        </div>
                        <div class="flex items-end pb-2">
                            <label class="flex items-center space-x-2 cursor-pointer group">
                                <input type="checkbox" id="d_platce_dph" class="w-4 h-4 text-blue-600 border-slate-300 rounded focus:ring-blue-500">
                                <span class="text-sm font-medium text-slate-600 group-hover:text-slate-900">Jsem plátce DPH</span>
                            </label>
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Adresa sídla</label>
                        <input type="text" id="d_adresa" value="<?= htmlspecialchars($config['d_adresa'] ?? '', ENT_QUOTES) ?>" class="form-input">
                    </div>
                </div>
            </div>

            <!-- Odběratel -->
            <div>
                <h2 class="text-lg font-bold text-slate-800 mb-4 border-b border-slate-200 pb-2">Odběratel</h2>
                <div class="space-y-4">
                    <div>
                        <label class="form-label">IČO</label>
                        <div class="flex rounded-md shadow-sm">
                            <input type="text" id="o_ico" class="form-input rounded-none rounded-l-md border-r-0 focus:z-10" placeholder="Např. 00000000">
                            <button type="button" onclick="fetchAres('o')" class="inline-flex items-center px-4 py-2 border border-blue-600 text-sm font-medium rounded-r-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-blue-500 transition">ARES</button>
                        </div>
                    </div>
                    <div>
                        <label class="form-label">Název klienta</label>
                        <input type="text" id="o_nazev" class="form-input font-semibold">
                    </div>
                    <div>
                        <label class="form-label">DIČ</label>
                        <input type="text" id="o_dic" class="form-input">
                    </div>
                    <div>
                        <label class="form-label">Fakturační adresa</label>
                        <input type="text" id="o_adresa" class="form-input">
                    </div>
                    <div>
                        <label class="form-label text-blue-600">E-mail klienta (pro automatické odeslání)</label>
                        <input type="email" id="o_email" class="form-input bg-blue-50 border-blue-200" placeholder="klient@email.cz">
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-slate-50 p-6 rounded-lg border border-slate-200 mb-10 grid grid-cols-2 gap-10">
            <div>
                <h3 class="text-sm font-bold text-slate-700 mb-4 uppercase tracking-wide">Platební údaje</h3>
                <div class="space-y-4">
                    <div>
                        <label class="form-label">Název Banky</label>
                        <input type="text" id="b_nazev" value="<?= htmlspecialchars($config['b_nazev'] ?? '', ENT_QUOTES) ?>" class="form-input">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="form-label">Číslo účtu</label>
                            <input type="text" id="b_ucet" value="<?= htmlspecialchars($config['b_ucet'] ?? '', ENT_QUOTES) ?>" class="form-input font-mono">
                        </div>
                        <div>
                            <label class="form-label">Variabilní symbol</label>
                            <input type="text" id="f_vs" class="form-input font-mono">
                        </div>
                    </div>
                    <div>
                        <label class="form-label">IBAN</label>
                        <input type="text" id="b_iban" value="<?= htmlspecialchars($config['b_iban'] ?? '', ENT_QUOTES) ?>" class="form-input font-mono uppercase">
                    </div>
                </div>
            </div>
            <div>
                <h3 class="text-sm font-bold text-slate-700 mb-4 uppercase tracking-wide">Termíny</h3>
                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="form-label">Vystaveno</label>
                            <input type="text" id="f_vystaveno" value="<?= date('d.m.Y') ?>" class="form-input">
                        </div>
                        <div>
                            <label class="form-label text-blue-600">Splatnost</label>
                            <input type="text" id="f_splatnost" value="<?= date('d.m.Y', strtotime('+14 days')) ?>" class="form-input font-bold text-blue-700 bg-white">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mb-8">
            <h3 class="text-sm font-bold text-slate-700 mb-3 uppercase tracking-wide">Položky faktury</h3>
            <div class="border border-slate-200 rounded-lg overflow-hidden">
                <table class="w-full text-left bg-white">
                    <thead class="bg-slate-100 border-b border-slate-200">
                        <tr>
                            <th class="py-2 px-4 text-xs font-semibold text-slate-600 w-1/2">Název položky</th>
                            <th class="py-2 px-4 text-xs font-semibold text-slate-600 text-center w-[15%]">Množství</th>
                            <th class="py-2 px-4 text-xs font-semibold text-slate-600 text-right w-[15%]">Cena/ks</th>
                            <th class="py-2 px-4 text-xs font-semibold text-slate-600 text-right w-[15%]">Celkem</th>
                            <th class="py-2 px-2 w-[5%]"></th>
                        </tr>
                    </thead>
                    <tbody id="itemsBody" class="divide-y divide-slate-100"></tbody>
                </table>
            </div>
            <button onclick="addRow()" class="mt-3 text-sm font-semibold text-blue-600 hover:text-blue-800 transition py-1 px-2 hover:bg-blue-50 rounded-md inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                Přidat řádek
            </button>
        </div>

        <div>
            <label class="form-label">Poznámka pro klienta (nepovinné)</label>
            <textarea id="f_poznamka" rows="2" class="form-input resize-y text-slate-600" placeholder="Děkujeme za spolupráci..."><?= htmlspecialchars($config['f_poznamka'] ?? '', ENT_QUOTES) ?></textarea>
        </div>

    </div>

    <div class="sticky-bar">
        <div class="max-w-5xl mx-auto px-6 flex justify-between items-center">
            
            <div class="flex items-center gap-4 bg-slate-100 px-4 py-2.5 rounded-lg border border-slate-200">
                <div class="flex flex-col gap-1">
                    <div class="text-[10px] font-bold text-slate-500 uppercase">SMTP Server pro odeslání</div>
                    <div class="flex gap-2">
                        <input type="text" id="e_server" placeholder="Server" value="<?= htmlspecialchars($config['e_server'] ?? '', ENT_QUOTES) ?>" class="form-input text-xs py-1 px-2 w-28 bg-white border-transparent">
                        <input type="text" id="e_login" placeholder="Login" value="<?= htmlspecialchars($config['e_login'] ?? '', ENT_QUOTES) ?>" class="form-input text-xs py-1 px-2 w-24 bg-white border-transparent">
                        <input type="password" id="e_heslo" placeholder="Heslo" class="form-input text-xs py-1 px-2 w-24 bg-white border-transparent">
                    </div>
                </div>
                <div class="border-l border-slate-300 h-8 mx-2"></div>
                <label class="flex items-center space-x-2 cursor-pointer group">
                    <input type="checkbox" id="send_email" class="w-5 h-5 text-blue-600 border-slate-300 rounded focus:ring-blue-500">
                    <span class="text-sm font-semibold text-slate-700 group-hover:text-blue-600 transition leading-tight">Po uložení odeslat<br>na e-mail klienta</span>
                </label>
            </div>

            <div class="flex items-center gap-6">
                <div class="text-right">
                    <div class="text-xs font-bold text-slate-500 uppercase tracking-wide">Celkem k úhradě</div>
                    <div class="text-3xl font-black text-slate-900 leading-none"><span id="grandTotal">0.00</span> Kč</div>
                </div>
                <button id="saveBtn" onclick="submitInvoice()" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg shadow-lg shadow-blue-600/20 font-bold text-base transition flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    Vytvořit Fakturu
                </button>
            </div>
            
        </div>
    </div>

    <script>
        function encodeLogo(element) {
            const file = element.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onloadend = function() {
                document.getElementById('logo_base64').value = reader.result;
                const preview = document.getElementById('logo_preview');
                preview.src = reader.result;
                preview.style.display = 'block';
            };
            reader.readAsDataURL(file);
        }

        async function fetchAres(prefix) {
            const ico = document.getElementById(`${prefix}_ico`).value.trim();
            if (!ico) return alert('Zadejte IČO');
            
            const btn = event.target;
            const originalText = btn.innerText;
            btn.innerText = '...';
            btn.disabled = true;

            try {
                const res = await fetch(`?action=ares&ico=${ico}`);
                const json = await res.json();
                
                if (json.success) {
                    document.getElementById(`${prefix}_nazev`).value = json.data.nazev;
                    document.getElementById(`${prefix}_adresa`).value = json.data.adresa;
                    document.getElementById(`${prefix}_dic`).value = json.data.dic;
                } else {
                    alert(json.error);
                }
            } catch (err) {
                alert('Při spojení se serverem došlo k chybě.');
            } finally {
                btn.innerText = originalText;
                btn.disabled = false;
            }
        }

        function addRow() {
            const tbody = document.getElementById('itemsBody');
            const row = document.createElement('tr');
            row.className = "hover:bg-slate-50 transition-colors group";
            
            row.innerHTML = `
                <td class="py-2 px-4"><textarea rows="1" class="desc form-input resize-y min-h-[40px] border-transparent bg-transparent hover:border-slate-300 focus:bg-white w-full" placeholder="Název položky..." oninput="this.style.height = ''; this.style.height = this.scrollHeight + 'px'"></textarea></td>
                <td class="py-2 px-4 align-top"><input type="number" oninput="this.value = Math.round(this.value);" onchange="calc()" class="qty form-input text-center font-semibold" value="1" min="1" step="1"></td>
                <td class="py-2 px-4 align-top"><input type="number" onchange="calc()" class="price form-input text-right font-mono" value="0" min="0" step="0.01"></td>
                <td class="py-2 px-4 text-right font-mono font-bold text-slate-800 align-top pt-4 row-total">0.00</td>
                <td class="py-2 px-2 text-center align-top pt-3 opacity-0 group-hover:opacity-100 transition-opacity"><button onclick="this.closest('tr').remove(); calc();" class="text-red-400 hover:text-red-600 p-1 rounded transition"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg></button></td>
            `;
            tbody.appendChild(row);
            calc();
        }

        function calc() {
            let grand = 0;
            document.querySelectorAll('#itemsBody tr').forEach(tr => {
                const qty = parseInt(tr.querySelector('.qty').value) || 0;
                const price = parseFloat(tr.querySelector('.price').value) || 0;
                const total = qty * price;
                tr.querySelector('.row-total').innerText = total.toFixed(2);
                grand += total;
            });
            document.getElementById('grandTotal').innerText = grand.toLocaleString('cs-CZ', {minimumFractionDigits: 2});
        }

        async function submitInvoice() {
            const btn = document.getElementById('saveBtn');
            const originalContent = btn.innerHTML;
            btn.innerHTML = `<svg class="animate-spin -ml-1 mr-2 h-5 w-5 text-white inline-block" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Zpracování...`;
            btn.disabled = true;

            const payload = {
                logo_base64: document.getElementById('logo_base64').value,
                d_ico: document.getElementById('d_ico').value,
                d_nazev: document.getElementById('d_nazev').value,
                d_dic: document.getElementById('d_dic').value,
                d_adresa: document.getElementById('d_adresa').value,
                d_platce_dph: document.getElementById('d_platce_dph').checked,
                o_ico: document.getElementById('o_ico').value,
                o_nazev: document.getElementById('o_nazev').value,
                o_dic: document.getElementById('o_dic').value,
                o_adresa: document.getElementById('o_adresa').value,
                o_email: document.getElementById('o_email').value,
                f_vystaveno: document.getElementById('f_vystaveno').value,
                f_splatnost: document.getElementById('f_splatnost').value,
                f_vs: document.getElementById('f_vs').value,
                f_cislo: document.getElementById('f_cislo').value,
                f_poznamka: document.getElementById('f_poznamka').value,
                b_nazev: document.getElementById('b_nazev').value,
                b_ucet: document.getElementById('b_ucet').value,
                b_iban: document.getElementById('b_iban').value,
                e_server: document.getElementById('e_server').value,
                e_login: document.getElementById('e_login').value,
                e_heslo: document.getElementById('e_heslo').value,
                send_email: document.getElementById('send_email').checked,
                items: []
            };

            document.querySelectorAll('#itemsBody tr').forEach(tr => {
                payload.items.push({
                    desc: tr.querySelector('.desc').value,
                    qty: parseInt(tr.querySelector('.qty').value) || 1,
                    price: parseFloat(tr.querySelector('.price').value) || 0
                });
            });

            try {
                const res = await fetch('', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                
                if (data.error) throw new Error(data.error);
                
                if (data.email_sent) {
                    alert('Úspěch! Faktura byla uložena a odeslána klientovi na e-mail.');
                }
                
                window.open(data.pdf_url, '_blank');
            } catch (e) {
                alert('Chyba: ' + e.message);
            } finally {
                btn.innerHTML = originalContent;
                btn.disabled = false;
            }
        }

        addRow();
    </script>
</body>
</html>