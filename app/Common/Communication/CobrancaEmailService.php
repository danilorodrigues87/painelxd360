<?php

namespace App\Common\Communication;

use App\Common\Helpers\NumeroHelper;
use App\Common\Helpers\DateTimeHelper;
use App\Common\Helpers\EmailValidator;
use App\Common\Helpers\CampanhaSegmentoHelper;
use App\Model\Entity\EmailCobrancaLog;
use App\Model\Entity\EscolaIntegracoes;
use App\Model\Entity\ClientesAssinantes;
use App\Model\Entity\Responsaveis;

class CobrancaEmailService {

	public static function getTemplatesPadrao(): array {
		return [
			'assunto_antes' => 'Lembrete: mensalidade vence em {dias} dia(s) — {escola}',
			'assunto_vencimento' => 'Sua mensalidade vence hoje — {escola}',
			'assunto_atraso' => 'Mensalidade em atraso há {dias} dia(s) — {escola}',
			'msg_antes' => '<p>Olá <strong>{nome}</strong>,</p>'
				.'<p>Passando para lembrar que a mensalidade abaixo vence em <strong>{dias} dia(s)</strong> ({vencimento}):</p>'
				.'<p><strong>{descricao}</strong><br>Valor: R$ {valor}</p>'
				.'<p>Evite juros e mantenha sua matrícula em dia.</p>'
				.'<p>Atenciosamente,<br><strong>{escola}</strong></p>',
			'msg_vencimento' => '<p>Olá <strong>{nome}</strong>,</p>'
				.'<p>Sua mensalidade <strong>vence hoje</strong> ({vencimento}):</p>'
				.'<p><strong>{descricao}</strong><br>Valor: R$ {valor}</p>'
				.'<p>Realize o pagamento para garantir os benefícios de pontualidade.</p>'
				.'<p>Atenciosamente,<br><strong>{escola}</strong></p>',
			'msg_atraso' => '<p>Olá <strong>{nome}</strong>,</p>'
				.'<p>Identificamos que a mensalidade abaixo está <strong>em atraso há {dias} dia(s)</strong> (vencimento {vencimento}):</p>'
				.'<p><strong>{descricao}</strong><br>Valor: R$ {valor}</p>'
				.'<p>Por favor, regularize o pagamento o quanto antes para evitar transtornos.</p>'
				.'<p>Atenciosamente,<br><strong>{escola}</strong></p>',
		];
	}

	public static function preview(int $idAdmin, array $override = []): array {
		if (!EscolaIntegracoes::temColunasCobranca()) {
			return [
				'ok'      => false,
				'erro'    => 'sql',
				'message' => 'Execute o SQL das colunas de cobrança em escola_integracoes.',
				'itens'   => [],
				'total'   => 0,
			];
		}

		if (!EmailCobrancaLog::tabelaExiste()) {
			return [
				'ok'      => false,
				'erro'    => 'sql',
				'message' => 'Execute o SQL da tabela email_cobranca_log.',
				'itens'   => [],
				'total'   => 0,
			];
		}

		$config = self::montarConfigPreview($idAdmin, $override);
		$itens = self::coletarPendentesEnvio($idAdmin, $config, false);

		return [
			'ok'            => true,
			'simulacao'     => true,
			'cobranca_ativa'=> (int)($config->cobranca_ativo ?? 0) === 1,
			'itens'         => $itens,
			'total'         => count($itens),
			'enviados_hoje' => EmailCobrancaLog::contarHoje($idAdmin),
		];
	}

	private static function montarConfigPreview(int $idAdmin, array $override): EscolaIntegracoes {
		$config = EscolaIntegracoes::getByIdAdmin($idAdmin);
		if (!$config instanceof EscolaIntegracoes) {
			$config = new EscolaIntegracoes;
			$config->id_admin = $idAdmin;
		}

		$config->cobranca_dias_antes = trim($override['cobranca_dias_antes'] ?? $config->cobranca_dias_antes ?? '3,5');
		$config->cobranca_dias_depois = trim($override['cobranca_dias_depois'] ?? $config->cobranca_dias_depois ?? '1,3,7');
		$config->cobranca_aviso_vencimento = isset($override['cobranca_aviso_vencimento'])
			? (int)$override['cobranca_aviso_vencimento']
			: (int)($config->cobranca_aviso_vencimento ?? 1);
		$config->cobranca_enviar_responsavel = isset($override['cobranca_enviar_responsavel'])
			? (int)$override['cobranca_enviar_responsavel']
			: (int)($config->cobranca_enviar_responsavel ?? 1);

		return $config;
	}

	public static function processar(int $idAdmin = 0, bool $dryRun = false): array {
		$resumo = [
			'escolas'   => 0,
			'enviados'  => 0,
			'ignorados' => 0,
			'erros'     => 0,
			'detalhes'  => [],
		];

		if (!EmailCobrancaLog::tabelaExiste()) {
			$resumo['erro'] = 'Tabela email_cobranca_log não existe.';
			return $resumo;
		}

		$escolas = self::listarEscolasAtivas($idAdmin);

		foreach ($escolas as $escolaId) {
			$config = EscolaIntegracoes::getByIdAdmin($escolaId);
			if (!$config instanceof EscolaIntegracoes) {
				continue;
			}

			$resumo['escolas']++;
			$pendentes = self::coletarPendentesEnvio($escolaId, $config, false);
			$nomeEscola = self::nomeEscola($escolaId);
			$email = Email::escola($escolaId);
			$delayEmail = max(1, (int)$config->email_delay_segundos);
			$delayWa = max(1, (int)($config->whatsapp_delay_segundos ?? 5));
			$maxWaHora = max(1, (int)($config->whatsapp_max_hora ?? 40));
			$waAtivo = EscolaIntegracoes::temColunasWhatsappAutomacao()
				&& (int)($config->cobranca_whatsapp_ativo ?? 0) === 1;
			$waStatus = $waAtivo ? WhatsappEscolaService::status($escolaId) : null;
			$waConectado = !empty($waStatus['conectado']);
			$enviadosWaHora = 0;

			foreach ($pendentes as $item) {
				if ($dryRun) {
					$resumo['ignorados']++;
					continue;
				}

				$vars = self::montarVariaveis($item, $nomeEscola);
				$assunto = self::aplicarTemplate(self::assuntoPorTipo($config, $item['tipo']), $vars);
				$corpo = self::aplicarTemplate(self::mensagemPorTipo($config, $item['tipo']), $vars);
				$textoWa = CampanhaSegmentoHelper::textoParaWhatsapp($corpo);

				$okEmail = false;
				$okWa = false;
				$ultimoErro = '';
				$destinoLog = '';

				foreach ($item['emails'] as $destino) {
					if ($email->sendEmail($destino, $assunto, $corpo)) {
						$okEmail = true;
						$destinoLog = $destino;
						sleep($delayEmail);
						break;
					}
					$ultimoErro = $email->getError() ?: 'Falha no e-mail';
				}

				if ($waAtivo && $waConectado && $textoWa !== '' && $enviadosWaHora < $maxWaHora) {
					foreach ($item['telefones'] as $tel) {
						$r = WhatsappEscolaService::enviarTexto($escolaId, $tel, $textoWa);
						if (!empty($r['ok'])) {
							$okWa = true;
							if ($destinoLog === '') {
								$destinoLog = 'wa:'.$tel;
							}
							$enviadosWaHora++;
							sleep($delayWa);
							break;
						}
						$ultimoErro = $r['message'] ?? 'Falha no WhatsApp';
					}
				}

				if ($okEmail || $okWa) {
					EmailCobrancaLog::registrar(
						$escolaId,
						(int)$item['caixa_id'],
						$item['tipo'],
						(int)$item['dias'],
						$destinoLog !== '' ? $destinoLog : ($item['emails'][0] ?? ($item['telefones'][0] ?? ''))
					);
					$resumo['enviados']++;
				} else {
					$resumo['erros']++;
					$resumo['detalhes'][] = [
						'caixa_id' => $item['caixa_id'],
						'erro'     => $ultimoErro ?: 'Sem e-mail/WhatsApp válido ou WhatsApp desconectado',
					];
				}
			}
		}

		return $resumo;
	}

	private static function listarEscolasAtivas(int $idAdminFiltro): array {
		if ($idAdminFiltro > 0) {
			$config = EscolaIntegracoes::getByIdAdmin($idAdminFiltro);
			if ($config instanceof EscolaIntegracoes && (int)$config->cobranca_ativo) {
				return [$idAdminFiltro];
			}
			return [];
		}

		if (!EscolaIntegracoes::tabelaExiste()) {
			return [];
		}

		$results = EscolaIntegracoes::get('cobranca_ativo = 1');
		$ids = [];
		while ($row = $results->fetchObject(EscolaIntegracoes::class)) {
			$ids[] = (int)$row->id_admin;
		}
		return $ids;
	}

	private static function coletarPendentesEnvio(int $idAdmin, EscolaIntegracoes $config, bool $incluirJaEnviados): array {
		$pendentes = [];
		$regras = self::montarRegras($config);

		foreach ($regras as $regra) {
			$titulos = self::buscarTitulos($idAdmin, $regra['tipo'], $regra['dias']);
			foreach ($titulos as $titulo) {
				if (!$incluirJaEnviados && EmailCobrancaLog::jaEnviou((int)$titulo['caixa_id'], $regra['tipo'], $regra['dias'])) {
					continue;
				}

				$emails = self::resolverEmails($titulo, $config);
				$telefones = self::resolverTelefones($titulo, $config);
				if (empty($emails) && empty($telefones)) {
					continue;
				}

				$pendentes[] = [
					'caixa_id'    => (int)$titulo['caixa_id'],
					'tipo'        => $regra['tipo'],
					'dias'        => $regra['dias'],
					'nome'        => $titulo['aluno_nome'],
					'descricao'   => $titulo['descricao'],
					'valor'       => $titulo['valor'],
					'vencimento'  => $titulo['vencimento'],
					'matricula_id'=> (int)$titulo['matricula_id'],
					'emails'      => $emails,
					'telefones'   => $telefones,
					'label'       => $regra['label'],
				];
			}
		}

		return $pendentes;
	}

	private static function montarRegras(EscolaIntegracoes $config): array {
		$regras = [];

		foreach (self::parseDias($config->cobranca_dias_antes ?? '3,5') as $dias) {
			$regras[] = [
				'tipo'  => 'antes',
				'dias'  => $dias,
				'label' => $dias.' dia(s) antes do vencimento',
			];
		}

		if ((int)($config->cobranca_aviso_vencimento ?? 1) === 1) {
			$regras[] = [
				'tipo'  => 'vencimento',
				'dias'  => 0,
				'label' => 'No dia do vencimento',
			];
		}

		foreach (self::parseDias($config->cobranca_dias_depois ?? '1,3,7') as $dias) {
			$regras[] = [
				'tipo'  => 'atraso',
				'dias'  => $dias,
				'label' => $dias.' dia(s) após o vencimento',
			];
		}

		return $regras;
	}

	private static function parseDias(?string $valor): array {
		if ($valor === null || trim($valor) === '') {
			return [];
		}
		$partes = preg_split('/[^0-9]+/', $valor);
		$dias = [];
		foreach ($partes as $p) {
			$n = (int)$p;
			if ($n > 0) {
				$dias[] = $n;
			}
		}
		return array_values(array_unique($dias));
	}

	private static function buscarTitulos(int $idAdmin, string $tipo, int $dias): array {
		$hoje = date('Y-m-d');
		$dataAlvo = $hoje;

		if ($tipo === 'antes') {
			$dataAlvo = date('Y-m-d', strtotime('+'.$dias.' days'));
		} elseif ($tipo === 'atraso') {
			$dataAlvo = date('Y-m-d', strtotime('-'.$dias.' days'));
		}

		$sql = '
			SELECT
				c.id AS caixa_id,
				c.descricao,
				c.valor,
				c.vencimento,
				c.id_ref AS matricula_id,
				u.nome AS aluno_nome,
				u.email AS aluno_email,
				u.whatsapp AS aluno_whatsapp,
				u.id_responsavel,
				m.id_responsavel AS matricula_responsavel
			FROM caixa c
			INNER JOIN matriculas m ON m.id = c.id_ref AND m.id_admin = c.id_admin
			INNER JOIN usuarios u ON u.id = m.id_aluno AND u.id_admin = c.id_admin
			WHERE c.id_admin = :id_admin
			  AND c.tipo_transacao = "Entrada"
			  AND c.vencimento = :data_alvo
			  AND (c.status = 0 OR c.status = "0" OR c.status = "Em aberto")
			  AND m.status = 0
		';

		$pdo = new \PDO(
			'mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_NAME').';charset=utf8mb4',
			getenv('DB_USER'),
			getenv('DB_PASS'),
			[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
		);
		$stmt = $pdo->prepare($sql);
		$stmt->execute(['id_admin' => $idAdmin, 'data_alvo' => $dataAlvo]);

		return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
	}

	private static function resolverEmails(array $titulo, EscolaIntegracoes $config): array {
		$emails = [];
		$alunoEmail = EmailValidator::normalizar($titulo['aluno_email'] ?? '');
		if (EmailValidator::isValido($alunoEmail)) {
			$emails[] = $alunoEmail;
		}

		if ((int)($config->cobranca_enviar_responsavel ?? 1) !== 1) {
			return $emails;
		}

		$idResp = (int)($titulo['matricula_responsavel'] ?? 0);
		if ($idResp <= 0) {
			$idResp = (int)($titulo['id_responsavel'] ?? 0);
		}

		if ($idResp <= 0) {
			return $emails;
		}

		$resp = Responsaveis::getResById($idResp);
		if (!$resp instanceof Responsaveis) {
			return $emails;
		}

		$respEmail = EmailValidator::normalizar($resp->email ?? '');
		if (EmailValidator::isValido($respEmail) && !in_array($respEmail, $emails, true)) {
			$emails[] = $respEmail;
		}

		return $emails;
	}

	private static function resolverTelefones(array $titulo, EscolaIntegracoes $config): array {
		$tels = [];
		$aluno = EvolutionApiService::normalizarTelefone((string)($titulo['aluno_whatsapp'] ?? ''));
		if ($aluno !== '' && strlen($aluno) >= 12) {
			$tels[] = $aluno;
		}

		if ((int)($config->cobranca_enviar_responsavel ?? 1) !== 1) {
			return $tels;
		}

		$idResp = (int)($titulo['matricula_responsavel'] ?? 0);
		if ($idResp <= 0) {
			$idResp = (int)($titulo['id_responsavel'] ?? 0);
		}
		if ($idResp <= 0) {
			return $tels;
		}

		$resp = Responsaveis::getResById($idResp);
		if (!$resp instanceof Responsaveis) {
			return $tels;
		}

		$respTel = EvolutionApiService::normalizarTelefone((string)($resp->whatsapp ?? ''));
		if ($respTel !== '' && strlen($respTel) >= 12 && !in_array($respTel, $tels, true)) {
			$tels[] = $respTel;
		}

		return $tels;
	}

	private static function montarVariaveis(array $item, string $nomeEscola): array {


		return [
			'{nome}'       => $item['nome'] ?? '',
			'{escola}'     => $nomeEscola,
			'{valor}'      => NumeroHelper::moedaBr((float)($item['valor'] ?? 0)),
			'{vencimento}' => DateTimeHelper::databr($item['vencimento'] ?? ''),
			'{dias}'       => (string)(int)($item['dias'] ?? 0),
			'{descricao}'  => $item['descricao'] ?? '',
			'{situacao}'   => $item['label'] ?? '',
		];
	}

	private static function aplicarTemplate(string $texto, array $vars): string {
		return str_replace(array_keys($vars), array_values($vars), $texto);
	}

	private static function assuntoPorTipo(EscolaIntegracoes $config, string $tipo): string {
		$padrao = self::getTemplatesPadrao();
		if ($tipo === 'antes') {
			return trim($config->cobranca_assunto_antes ?? '') ?: $padrao['assunto_antes'];
		}
		if ($tipo === 'vencimento') {
			return trim($config->cobranca_assunto_vencimento ?? '') ?: $padrao['assunto_vencimento'];
		}
		return trim($config->cobranca_assunto_atraso ?? '') ?: $padrao['assunto_atraso'];
	}

	private static function mensagemPorTipo(EscolaIntegracoes $config, string $tipo): string {
		$padrao = self::getTemplatesPadrao();
		if ($tipo === 'antes') {
			return trim($config->cobranca_msg_antes ?? '') ?: $padrao['msg_antes'];
		}
		if ($tipo === 'vencimento') {
			return trim($config->cobranca_msg_vencimento ?? '') ?: $padrao['msg_vencimento'];
		}
		return trim($config->cobranca_msg_atraso ?? '') ?: $padrao['msg_atraso'];
	}

	private static function nomeEscola(int $idAdmin): string {
		$escola = ClientesAssinantes::getEscolaById($idAdmin);
		return ($escola instanceof ClientesAssinantes) ? ($escola->nome ?? '') : '';
	}
}
