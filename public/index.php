<?php

\define('K_TCPDF_THROW_EXCEPTION_ERROR', true);

require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

use Endroid\QrCode\QrCode;

\date_default_timezone_set('Europe/Kiev');

$app = new Silex\Application();

$app['debug'] = 1 == getenv('DEBUG');

$app->error(function (\Exception $e, Request $request, $code) {
    if (!$app['debug']) {
        exit;
    }
    return new Response($e->getMessage() . '<br /><pre>' . \print_r($e->getTrace(), 1) . '</pre>');
});

$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => __DIR__.'/../views',
));

$app->get('/stops/{id}/pdf/{type}', function($id, $type) use ($app) {
    $client = new GuzzleHttp\Client();

    $response = $client->request('GET', "https://api.lad.lviv.ua/stops/$id");

    $data = \json_decode($response->getBody(), true);
    $code = ltrim($data['code'], '0');

    array_map(function($fontName) {
        TCPDF_FONTS::addTTFfont(__DIR__ . '/../fonts/' . $fontName, 'TrueTypeUnicode', '', 96);
    }, ['DINPro-Regular.ttf', 'DINPro-Bold Regular.ttf']);

    // We need to group routes by vehicle type
    $groupedRoutes = [
        'bus' =>  [
            'image' => 'http://i.imgur.com/L7SBH90.png',
            'routes' => [],
        ],
        'tram' => [
            'image' => 'http://i.imgur.com/zENYrdx.png',
            'routes' => [],
        ],
        'trol' => [
            'image' => 'http://i.imgur.com/zENYrdx.png',
            'routes' => [],
        ]
    ];
    
    $data['routes'] = array_map(function($i) {
        return $i['name'];
    }, $data['routes']);
    sort($data['routes']);

    foreach($data['routes'] as $routeName) {
        $group = 'bus';
        if(0 === strpos($routeName, 'Тp')) {
            $group = 'trol';
        } elseif (0 === strpos($routeName, 'Т')) {
            $group = 'tram';
        }

        // Skip airport route
        if (false !== strpos($routeName, 'Аеропорт')) {
            continue;
        }

        $routeName = str_replace(['Н', '-рем', 'Тр', 'А', 'Т'], ['N', '', 'T', 'A', 'T'], $routeName);
        $routeName = preg_replace('/^N(\d{2})/', '$1H', $routeName);
        $routeName = substr($routeName, 1);
        $routeName = ltrim($routeName, 0);

        if(!in_array($routeName, $groupedRoutes[$group]['routes'], true)) {
            $groupedRoutes[$group]['routes'][] = $routeName;
        }

    }

    $html = $app['twig']->render('stop-sign.html.twig', [
        'code' => $code,
        'routes' => $groupedRoutes,
    ]);

    $pdf = new TCPDF('L', PDF_UNIT, [500 - 80, 350 - 80], true, 'UTF-8', false, true);
    $pdf->showImageErrors = true;

    $pdf->SetPrintHeader(false);
    $pdf->SetPrintFooter(false);
    $pdf->SetAutoPageBreak(false);
    $pdf->SetMargins(0, 0);
    $pdf->setCellMargins(0, 0, 0, 0);
    $pdf->setCellPaddings(0, 0, 0, 0);
    $pdf->setFontSubsetting(true);
    $pdf->AddPage();
    $pdf->SetFont('dinpro');

    $pdf->writeHTMLCell(0, 0, '', '', $html, 0, 1, 0, true, '', true);
    $pdf->Output($data['code'] . '.pdf', 'I');
});

$app->run();
