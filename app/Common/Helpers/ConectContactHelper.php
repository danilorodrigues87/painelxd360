<?php

namespace App\Common\Helpers;

use App\Common\Environment;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

class ConectContactHelper {

	private const RATE_WINDOW_SEC = 3600;
	private const RATE_MAX = 5;

	public static function destinoEmail(): string {
		return trim((string)Environment::get('CONECT_CONTACT_EMAIL', 'contato@conectajovem.com.br'));
	}

	public static function ipCliente(): string {
		foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
			$raw = (string)($_SERVER[$key] ?? '');
			if ($raw === '') {
				continue;
			}
			if ($key === 'HTTP_X_FORWARDED_FOR') {
				$raw = trim(explode(',', $raw)[0]);
			}
			if (filter_var($raw, FILTER_VALIDATE_IP)) {
				return $raw;
			}
		}
		return '0.0.0.0';
	}

	public static function permitirEnvio(string $ip): bool {
		$dir = sys_get_temp_dir().'/conect_contact_rate';
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		$file = $dir.'/'.md5($ip).'.json';
		$now = time();
		$data = ['times' => []];
		if (is_file($file)) {
			$decoded = json_decode((string)file_get_contents($file), true);
			if (is_array($decoded) && isset($decoded['times']) && is_array($decoded['times'])) {
				$data = $decoded;
			}
		}
		$data['times'] = array_values(array_filter(
			$data['times'],
			static fn($t) => is_int($t) && $t > ($now - self::RATE_WINDOW_SEC)
		));
		if (count($data['times']) >= self::RATE_MAX) {
			return false;
		}
		$data['times'][] = $now;
		@file_put_contents($file, json_encode($data));
		return true;
	}

	/**
	 * @return array{ok:bool,message:string}
	 */
	public static function enviar(string $nome, string $email, string $assunto, string $mensagem, string $whatsapp = ''): array {
		$nome = trim($nome);
		$email = strtolower(trim($email));
		$assunto = trim($assunto);
		$mensagem = trim($mensagem);
		$whatsapp = preg_replace('/\D+/', '', trim($whatsapp));

		if ($nome === '' || $email === '' || $mensagem === '') {
			return ['ok' => false, 'message' => 'Preencha nome, e-mail e mensagem.'];
		}
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return ['ok' => false, 'message' => 'E-mail inválido.'];
		}
		if (mb_strlen($nome) > 120 || mb_strlen($assunto) > 200 || mb_strlen($mensagem) > 5000) {
			return ['ok' => false, 'message' => 'Mensagem muito longa.'];
		}
		if ($whatsapp !== '' && (strlen($whatsapp) < 10 || strlen($whatsapp) > 13)) {
			return ['ok' => false, 'message' => 'WhatsApp inválido.'];
		}

		$ip = self::ipCliente();
		if (!self::permitirEnvio($ip)) {
			return ['ok' => false, 'message' => 'Muitas tentativas. Aguarde e tente novamente.'];
		}

		$config = self::configSmtp();
		if (empty($config['host']) || empty($config['user']) || empty($config['from_email'])) {
			return ['ok' => false, 'message' => 'Serviço de e-mail temporariamente indisponível.'];
		}

		$destino = self::destinoEmail();
		if ($destino === '' || !filter_var($destino, FILTER_VALIDATE_EMAIL)) {
			return ['ok' => false, 'message' => 'Destino de contato não configurado.'];
		}

		$titulo = $assunto !== '' ? $assunto : 'Contato pelo site';
		$subject = '[Conecta Jovem] '.$titulo;
		$nomeEsc = htmlspecialchars($nome, ENT_QUOTES, 'UTF-8');
		$emailEsc = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
		$assuntoEsc = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
		$mensagemEsc = nl2br(htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8'));
		$ipEsc = htmlspecialchars($ip, ENT_QUOTES, 'UTF-8');

		$waEsc = $whatsapp !== '' ? htmlspecialchars($whatsapp, ENT_QUOTES, 'UTF-8') : '—';
		$html = '<div style="font-family:system-ui,sans-serif;line-height:1.5;color:#111;">'
			.'<p><strong>Nova mensagem pelo site Conecta Jovem</strong></p>'
			.'<p><strong>Nome:</strong> '.$nomeEsc.'<br>'
			.'<strong>E-mail:</strong> '.$emailEsc.'<br>'
			.'<strong>WhatsApp:</strong> '.$waEsc.'<br>'
			.'<strong>Assunto:</strong> '.$assuntoEsc.'<br>'
			.'<strong>IP:</strong> '.$ipEsc.'</p>'
			.'<p><strong>Mensagem:</strong></p>'
			.'<p>'.$mensagemEsc.'</p>'
			.'</div>';

		$mail = new PHPMailer(true);
		try {
			$mail->isSMTP();
			$mail->Host = $config['host'];
			$mail->SMTPAuth = true;
			$mail->Username = $config['user'];
			$mail->Password = $config['pass'] ?? '';
			$mail->SMTPSecure = self::resolveEncryption($config['encryption'] ?? 'tls');
			$mail->Port = (int)($config['port'] ?? 587);
			$mail->CharSet = $config['charset'] ?? 'UTF-8';
			$mail->Encoding = 'base64';
			$mail->setFrom($config['from_email'], $config['from_name'] ?? 'Conecta Jovem');
			$mail->addAddress($destino);
			$mail->addReplyTo($email, $nome);
			$mail->isHTML(true);
			$mail->Subject = $subject;
			$mail->Body = $html;
			$mail->AltBody = strip_tags(str_replace('<br>', "\n", $html));
			$mail->send();
			return ['ok' => true, 'message' => 'Mensagem enviada com sucesso. Retornaremos em breve.'];
		} catch (Exception $e) {
			return ['ok' => false, 'message' => 'Não foi possível enviar agora. Tente novamente ou use o WhatsApp.'];
		}
	}

	private static function configSmtp(): array {
		return [
			'host'        => Environment::get('CONECT_SMTP_HOST', ''),
			'user'        => Environment::get('CONECT_SMTP_USER', ''),
			'pass'        => Environment::get('CONECT_SMTP_PASS', ''),
			'port'        => (int)Environment::get('CONECT_SMTP_PORT', 587),
			'charset'     => Environment::get('CONECT_SMTP_CHARSET', 'UTF-8'),
			'from_email'  => Environment::get('CONECT_SMTP_FROM_EMAIL', ''),
			'from_name'   => Environment::get('CONECT_SMTP_FROM_NAME', 'Conecta Jovem'),
			'encryption'  => Environment::get('CONECT_SMTP_ENCRYPTION', 'tls'),
		];
	}

	private static function resolveEncryption(string $encryption) {
		$encryption = strtolower($encryption);
		if ($encryption === 'ssl') {
			return PHPMailer::ENCRYPTION_SMTPS;
		}
		if ($encryption === 'none') {
			return false;
		}
		return PHPMailer::ENCRYPTION_STARTTLS;
	}
}
