Jednoduchý a efektivní PHP nástroj pro generování PDF faktur vytvořený z frustrace nad omezeními a nuceným brandingem existujících služeb. 

## Stack
- **Backend:** PHP 8.1+ (Strict types)
- **PDF Engine:** [mPDF](https://github.com/mpdf/mpdf)
- **Frontend:** HTML5, Tailwind CSS, JavaScript (Vanilla)
- **QR Engine:** [chillerlan/php-qrcode](https://github.com/chillerlan/php-qrcode)
- **Mailing:** [PHPMailer](https://github.com/PHPMailer/PHPMailer)

## Rychlá instalace

1. Naklonujte repozitář:
   ```bash
   git clone [https://github.com/vase-jmeno/open-invoicer-cz.git](https://github.com/vase-jmeno/open-invoicer-cz.git)
   cd open-invoicer-cz
   
2. Instalace závislostí
Projekt využívá Composer pro správu knihoven:
   Bash
   composer require mpdf/mpdf chillerlan/php-qrcode phpmailer/phpmailer

3. Nastavení práv
Pro správné fungování generování PDF a ukládání konfigurace nastavte práva zápisu:

   Bash
   chmod -R 775 storage/
   # nebo
   chmod -R 775 invoices/

4. Spuštění
Nasměrujte svůj webový server (Apache/Nginx) do kořenového adresáře projektu.
