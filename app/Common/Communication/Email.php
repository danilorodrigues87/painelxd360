<?php

namespace App\Common\Communication;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use App\Common\Environment;
use App\Model\Entity\EscolaIntegracoes;

class Email {

	private $config;
	private $error = null;
	private $usandoSistema = false;

	private function __construct($config, $usandoSistema = false) {
		$this->config = $config;
		$this->usandoSistema = $usandoSistema;
	}

	public function getError() {
		return $this->error;
	}

	public function isUsandoSistema(): bool {
		return $this->usandoSistema;
	}

	public static function sistema(): self {
		return new self(self::configSistema(), true);
	}

	public static function escola(int $idAdmin): self {
		$configEscola = self::configEscola($idAdmin);
		if ($configEscola !== null) {
			return new self($configEscola, false);
		}
		return self::sistema();
	}

	public static function escolaParaTeste(int $idAdmin, array $override = []): self {
		$config = self::configEscola($idAdmin, $override);
		if ($config === null) {
			return self::sistema();
		}
		return new self($config, false);
	}

	public static function getRemetenteSistema(): array {
		$config = self::configSistema();
		return [
			'email' => $config['from_email'] ?? '',
			'nome'  => $config['from_name'] ?? '',
		];
	}

	private static function configSistema(): array {
		return [
			'host'        => Environment::get('SMTP_HOST', ''),
			'user'        => Environment::get('SMTP_USER', ''),
			'pass'        => Environment::get('SMTP_PASS', ''),
			'port'        => (int)Environment::get('SMTP_PORT', 587),
			'charset'     => Environment::get('SMTP_CHARSET', 'UTF-8'),
			'from_email'  => Environment::get('SMTP_FROM_EMAIL', ''),
			'from_name'   => Environment::get('SMTP_FROM_NAME', 'CTI Educacional'),
			'encryption'  => Environment::get('SMTP_ENCRYPTION', 'tls'),
		];
	}

	private static function configEscola(int $idAdmin, array $override = []): ?array {
		$integracao = EscolaIntegracoes::getByIdAdmin($idAdmin);
		if (!$integracao instanceof EscolaIntegracoes) {
			return null;
		}

		$host = $override['smtp_host'] ?? $integracao->smtp_host;
		$user = $override['smtp_user'] ?? $integracao->smtp_user;
		$fromEmail = $override['smtp_from_email'] ?? $integracao->smtp_from_email;
		$ativo = isset($override['smtp_ativo'])
			? (int)$override['smtp_ativo'] === 1
			: (int)$integracao->smtp_ativo === 1;

		$pass = $override['smtp_pass'] ?? null;
		if (($pass === null || $pass === '') && !empty($override['manter_senha'])) {
			$pass = $integracao->getSenhaDescriptografada();
		} elseif ($pass === null || $pass === '') {
			$pass = $integracao->getSenhaDescriptografada();
		}

		if (!$ativo || empty($host) || empty($user) || empty($fromEmail) || empty($pass)) {
			return null;
		}

		return [
			'host'        => $host,
			'user'        => $user,
			'pass'        => $pass,
			'port'        => (int)($override['smtp_port'] ?? $integracao->smtp_port ?? 587),
			'charset'     => 'UTF-8',
			'from_email'  => $fromEmail,
			'from_name'   => $override['smtp_from_name'] ?? $integracao->smtp_from_name ?? '',
			'encryption'  => $override['smtp_encryption'] ?? $integracao->smtp_encryption ?? 'tls',
		];
	}

	private function resolveEncryption(string $encryption) {
		$encryption = strtolower($encryption);
		if ($encryption === 'ssl') {
			return PHPMailer::ENCRYPTION_SMTPS;
		}
		if ($encryption === 'none') {
			return false;
		}
		return PHPMailer::ENCRYPTION_STARTTLS;
	}

	public function sendEmail(
		$addresses,
		$subject,
		$body,
		$attachments = [],
		$ccs = [],
		$bccs = []
	) {
		$this->error = null;

		if (empty($this->config['host']) || empty($this->config['user']) || empty($this->config['from_email'])) {
			$this->error = 'Configuração SMTP incompleta.';
			return false;
		}

		$mail = new PHPMailer(true);

		try {
			$mail->isSMTP();
			$mail->Host       = $this->config['host'];
			$mail->SMTPAuth   = true;
			$mail->Username   = $this->config['user'];
			$mail->Password   = $this->config['pass'] ?? '';
			$mail->SMTPSecure = $this->resolveEncryption($this->config['encryption'] ?? 'tls');
			$mail->Port       = (int)($this->config['port'] ?? 587);
			$mail->CharSet    = $this->config['charset'] ?? 'UTF-8';
			$mail->Encoding   = 'base64';

			$mail->setFrom($this->config['from_email'], $this->config['from_name'] ?? '');

			foreach ((array)$addresses as $address) {
				if (!empty($address)) {
					$mail->addAddress($address);
				}
			}

			foreach ((array)$attachments as $attachment) {
				if (!empty($attachment)) {
					$mail->addAttachment($attachment);
				}
			}

			foreach ((array)$ccs as $cc) {
				if (!empty($cc)) {
					$mail->addCC($cc);
				}
			}

			foreach ((array)$bccs as $bcc) {
				if (!empty($bcc)) {
					$mail->addBCC($bcc);
				}
			}

			$mail->isHTML(true);
			$mail->Subject = $subject;
			$mail->Body    = $body;
			$mail->AltBody = strip_tags($body);

			return $mail->send();

		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return false;
		}
	}
}
