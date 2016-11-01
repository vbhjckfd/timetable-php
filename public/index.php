<?php

require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

use Endroid\QrCode\QrCode;

\date_default_timezone_set('Europe/Kiev');

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

        $timetable[$row['route']][] = $row['time_left'];
    });

    \ksort($timetable, \SORT_LOCALE_STRING);

    $timetable = array_map(function($key, $row) {
        $result =  $key . ': ' . \implode(', ', \array_slice($row, 0, 2));

        $result = \str_replace(['Тр', 'А', 'Т', 'Н', 'хв'], ['Tp', 'A', 'T', 'H', 'm'], $result);
        $result = \str_replace(' m', 'm', $result);

        return $result;
    }, array_keys($timetable), $timetable);

    while(strlen($timetable = \implode(\PHP_EOL, $timetable)) > 160) {
        \array_pop($timetable);
    }

    $writer = new \XMLWriter();
    $writer->openMemory();
    $writer->startDocument('1.0','UTF-8');
    $writer->setIndent(4);

    $writer->startElement('answer');
        $writer->writeAttribute('type', 'sync');
        $writer->startElement('body');
            $writer->writeAttribute('paid', 'false');
            $writer->writeCData($timetable);
        $writer->endElement();
    $writer->endElement();
    $writer->endDocument();
    $xml = $writer->flush();

    return new Response($xml, Response::HTTP_OK, ['Content-Type' => 'application/xml']);
});

$app->run();
