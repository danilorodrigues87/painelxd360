<?php 
/*
ini_set('display_errors', 1);
error_reporting(E_ALL);
*/
require __DIR__.'/../vendor/autoload.php';

use \App\Utils\View;
use \App\Common\Environment;
use \App\Model\Db\Database;
use \App\Http\Middleware\Queue as MiddlewareQueue;

//CARREGA VARIAVEIS DE AMBIENTE
Environment::load(__DIR__.'/../');

//DETECTA URL DA APLICAÇÃO (local ou produção)
$detectRequestUrl = function(){
	$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
		|| (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
	$scheme = $https ? 'https' : 'http';
	$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
	$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
	if($scriptDir === '/' || $scriptDir === '.'){
		$scriptDir = '';
	}
	return rtrim($scheme.'://'.$host.$scriptDir, '/');
};

$envUrl = rtrim((string)(getenv('URL') ?: ''), '/');
$requestUrl = $detectRequestUrl();
$envHost = parse_url($envUrl, PHP_URL_HOST);
$requestHost = $_SERVER['HTTP_HOST'] ?? '';
$envPath = rtrim((string)(parse_url($envUrl, PHP_URL_PATH) ?? ''), '/');
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
if($scriptDir === '/' || $scriptDir === '.'){
	$scriptDir = '';
}
$scriptPath = rtrim($scriptDir, '/');

// Host do .env diferente do request (ex.: localhost vs 127.0.0.1) → URL real
// Path do .env diferente da pasta do index.php → path real (evita 404 em produção)
if ($envHost && $requestHost && strcasecmp((string)$envHost, (string)$requestHost) !== 0) {
	$appUrl = $requestUrl;
} elseif ($envUrl !== '' && $envPath === $scriptPath) {
	$appUrl = $envUrl;
} elseif ($envHost) {
	$scheme = parse_url($envUrl, PHP_URL_SCHEME) ?: (($requestUrl !== '' && str_starts_with($requestUrl, 'https')) ? 'https' : 'http');
	$appUrl = rtrim($scheme.'://'.$envHost.$scriptPath, '/');
} else {
	$appUrl = $requestUrl;
}

//DEFINE A CONSTANTE DE URL (sem barra final)
define('URL', rtrim($appUrl, '/'));
define('SITE', getenv('SITE'));
define('TIMEZONE', getenv('TIMEZONE'));
date_default_timezone_set(TIMEZONE);
// Não forçar text/html aqui: a API aluno precisa de application/json.
// Páginas HTML definem o Content-Type no Response.

//DEFINE A CONSTANTE DE URL
define('SYSTEM_TOKEN', (string)Environment::get('SYSTEM_TOKEN', ''));

//DEFINE O VALOR PADRÃO DAS VARIAVEIS
View::init([
'URL' => URL
]);

//DEFINE O MAPEAMENTO DE MIDDLEWARES
MiddlewareQueue::setMap([
	'resolve-tenant' => \App\Http\Middleware\ResolveTenant::class,
	'maintenance' => \App\Http\Middleware\Maintenance::class,
	'required-admin-logout' => \App\Http\Middleware\RequireAdminLogout::class,
	'required-admin-login' => \App\Http\Middleware\RequireAdminLogin::class,
	'required-master-login' => \App\Http\Middleware\RequireMasterLogin::class,
	'api' => \App\Http\Middleware\Api::class,
	'user-basic-auth' => \App\Http\Middleware\UserBasicAuth::class,
	'jwt-auth' => \App\Http\Middleware\JWTAuth::class,
	'student-jwt' => \App\Http\Middleware\StudentJwtAuth::class,
	'editor-jwt' => \App\Http\Middleware\EditorJwtAuth::class,
	'cors-student' => \App\Http\Middleware\CorsStudent::class,
	'cors-conect' => \App\Http\Middleware\CorsConect::class,
	'candidato-jwt' => \App\Http\Middleware\CandidatoJwtAuth::class,
	'empresa-jwt' => \App\Http\Middleware\EmpresaJwtAuth::class,
	'conect-portal-jwt' => \App\Http\Middleware\ConectPortalJwtAuth::class,
	'cache' => \App\Http\Middleware\Cache::class
]);

//DEFINE O MAPEAMENTO DE MIDDLEWARES PADRÕES (EXECUTA EM TODAS AS ROTAS)
MiddlewareQueue::setDefault([
	'resolve-tenant',
	'maintenance',
]);