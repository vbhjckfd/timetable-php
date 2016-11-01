<?php

require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

use Endroid\QrCode\QrCode;

define('K_TCPDF_THROW_EXCEPTION_ERROR', true);

$app = new Silex\Application();

$app->error(function (\Exception $e, Request $request, $code) {
    return new Response($e->getMessage() . '<br /><pre>' . \print_r($e->getTrace(), 1) . '</pre>');
});

$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/../views',
));

$app->get('/stops/{id}/pdf', function($id) use($app) {
    $client = new GuzzleHttp\Client();

    $response = $client->request('GET', "https://lad.lviv.ua/api/stops/$id");

    $data = \json_decode($response->getBody(), true);
    $code = ltrim($data['code'], '0');


    $qrCode = new QrCode();
    $qrCode
        ->setText("https://lad.lviv.ua/stops/$code?utm_source=stop&utm_medium=qr-code")
        ->setSize(220)
        ->setPadding(0)
        ->setErrorCorrection('high')
        ->setForegroundColor(array('r' => 0, 'g' => 0, 'b' => 0, 'a' => 0))
        ->setBackgroundColor(array('r' => 255, 'g' => 255, 'b' => 255, 'a' => 0))
        ->setImageType(QrCode::IMAGE_TYPE_PNG)
    ;

    $html = $app['twig']->render('stop-flyer.html', array(
        'name' => $data['name'],
        'qrCode' => $qrCode->getDataUri(),
        'code' => $code,
    ));

    array_map(function($fontName){
        TCPDF_FONTS::addTTFfont(__DIR__ . '/../fonts/' . $fontName, 'TrueTypeUnicode', '', 96);
    }, ['DINPro-Regular.ttf', 'DINPro-Bold Regular.ttf']);

    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, 'DLP', true, 'UTF-8', false, true);

    $pdf->SetPrintHeader(false);
    $pdf->SetPrintFooter(false);
    $pdf->SetAutoPageBreak(false);
    $pdf->SetMargins(0, 0);
    $pdf->setCellMargins(0, 0, 0, 0);
    $pdf->setCellPaddings(0, 0, 0, 0);
    $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
    $pdf->setFontSubsetting(true);
    $pdf->AddPage();
    $pdf->SetFont('dinpro');

    $pdf->writeHTMLCell(0, 0, '', '', $html, 0, 1, 0, true, '', true);
    $pdf->Output('Stop_' . $data['code'] . '.pdf', 'I');
});

$app->post('/partners/startmobile', function(Request $request) use ($app) {
    $xmlRequest = new \SimpleXMLElement($request->getContent());

    $code = $xmlRequest->body;

    $client = new GuzzleHttp\Client();
    $response = $client->request('GET', "https://lad.lviv.ua/api/stops/$code");
    $data = \json_decode($response->getBody(), true);

    $timetable = [];
    array_walk($data['timetable'], function($row) use (&$timetable) {
        if(!array_key_exists($row['route'], $timetable)) {
            $timetable[$row['route']] = [];
        }

        $timetable[$row['route']][] = $row['seconds_left'];
    });

    /*
<message>
    <service type="type" timestamp="message_timestamp" auth="authorization_line" request_id=”id” />
    <from>+380675106567</from>
    <to>2215</to>
    <body content-type="text-plain" encoding="plain">
    ɬɟɤɫɬɫɨɨɛɳɟɧɢɹ
        </body>
</message>
    */

    $response = new \SimpleXMLElement('<answer/>');
        $response->addAttribute('type', 'sync');
    $response->addChild('body', print_r($timetable, 1));
        $response->addAttribute('paid', 'false');

    return $response->asXML();
});

$app->run();
