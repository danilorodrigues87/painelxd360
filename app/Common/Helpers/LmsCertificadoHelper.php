<?php

namespace App\Common\Helpers;

use App\Model\Entity\ClientesAssinantes;
use App\Common\Helpers\ConectCandidatoFormacaoHelper;
use App\Model\Entity\LmsCertificado;
use App\Model\Entity\LmsCurso;
use App\Model\Entity\LmsModulo;
use App\Model\Entity\Trilhas;

/**
 * Emite certificado simbólico do portal EAD (tabela lms_certificados).
 * Automático em 100%; atualiza snapshot ao reconcluir após edição do curso.
 * Não usa a tabela comercial `certificados`.
 */
class LmsCertificadoHelper {

	/**
	 * Emite ou atualiza certificado se o curso estiver 100%.
	 * Mantém o mesmo codigo; atualiza titulo/modulos/carga/conclusao.
	 */
	public static function emitirSeCursoCompleto(int $idAluno, int $idAdmin, LmsCurso $curso): ?LmsCertificado {
		if (!LmsCertificado::tabelasExistem() || $idAluno <= 0 || $idAdmin <= 0 || (int)$curso->id <= 0) {
			return null;
		}

		$mapped = StudentApiMapper::course($curso, $idAluno, $idAdmin, false);
		$progress = (int)($mapped['progressPercent'] ?? 0);
		if ($progress < 100) {
			return null;
		}

		$snap = self::montarSnapshot($curso, $mapped, $idAdmin);
		$existente = LmsCertificado::getByAlunoCurso($idAluno, (int)$curso->id);

		if ($existente instanceof LmsCertificado) {
			$mudou = self::snapshotDiferente($existente, $snap);
			$existente->titulo_curso = $snap['titulo_curso'];
			$existente->nome_escola = $snap['nome_escola'];
			$existente->carga_h = $snap['carga_h'];
			$existente->modulos = $snap['modulos'];
			$existente->id_trilha = $snap['id_trilha'];
			$existente->conclusao = date('Y-m-d');
			$existente->atualizar();

			if ($mudou) {
				LmsNotificacaoHelper::criar(
					$idAdmin,
					$idAluno,
					'certificate',
					'Certificado atualizado',
					$snap['titulo_curso'],
					'/certificates',
					'cert-upd:'.(int)$existente->id
				);
			}

			LmsConquistaHelper::recalcular($idAdmin, $idAluno);
			$fresh = LmsCertificado::getById((int)$existente->id) ?: $existente;
			if ($fresh instanceof LmsCertificado) {
				ConectCandidatoFormacaoHelper::syncFromCertificado($fresh);
			}
			return $fresh;
		}

		$ob = new LmsCertificado();
		$ob->id_admin = $idAdmin;
		$ob->id_aluno = $idAluno;
		$ob->id_curso = (int)$curso->id;
		$ob->id_trilha = $snap['id_trilha'];
		$ob->titulo_curso = $snap['titulo_curso'];
		$ob->nome_escola = $snap['nome_escola'];
		$ob->carga_h = $snap['carga_h'];
		$ob->modulos = $snap['modulos'];
		$ob->codigo = bin2hex(random_bytes(8));
		$ob->conclusao = date('Y-m-d');
		$ob->cadastrar();

		LmsNotificacaoHelper::criar(
			$idAdmin,
			$idAluno,
			'certificate',
			'Certificado disponível',
			$snap['titulo_curso'],
			'/certificates',
			'cert:'.(int)$ob->id
		);

		LmsConquistaHelper::recalcular($idAdmin, $idAluno);

		$emitido = LmsCertificado::getById((int)$ob->id) ?: $ob;
		if ($emitido instanceof LmsCertificado) {
			ConectCandidatoFormacaoHelper::syncFromCertificado($emitido);
		}
		return $emitido;
	}

	/**
	 * Lista certificados do aluno com status valid|outdated (progresso ao vivo).
	 * Backfill: se o aluno já está 100% em algum curso e ainda não tem linha em
	 * lms_certificados (ex.: concluiu antes da emissão automática), emite agora.
	 * @return array<int,array<string,mixed>>
	 */
	public static function listForApi(int $idAdmin, int $idAluno): array {
		if (!LmsCertificado::tabelasExistem() || $idAluno <= 0 || $idAdmin <= 0) {
			return [];
		}

		self::backfillCursosCompletos($idAdmin, $idAluno);

		$stmt = LmsCertificado::listByAluno($idAluno, $idAdmin);
		$out = [];
		while ($c = $stmt->fetchObject(LmsCertificado::class)) {
			if (!$c instanceof LmsCertificado) {
				continue;
			}
			$curso = LmsCurso::getByIdAdmin((int)$c->id_curso, $idAdmin);
			$progress = 0;
			$status = 'outdated';

			if ($curso instanceof LmsCurso) {
				$mapped = StudentApiMapper::course($curso, $idAluno, $idAdmin, false);
				$progress = (int)($mapped['progressPercent'] ?? 0);
				if ($progress >= 100) {
					$status = 'valid';
					$fresh = self::emitirSeCursoCompleto($idAluno, $idAdmin, $curso);
					if ($fresh instanceof LmsCertificado) {
						$c = $fresh;
					}
				}
			}

			$out[] = self::formatItem($c, $status, $progress);
		}
		return $out;
	}

	/**
	 * Emite certificado para cursos em que o aluno já está 100% e ainda não há registro.
	 */
	public static function backfillCursosCompletos(int $idAdmin, int $idAluno): void {
		if (!LmsCertificado::tabelasExistem() || !LmsHelper::tabelasExistem()) {
			return;
		}
		foreach (StudentEntitlement::idsTrilhasMatriculadas($idAluno, $idAdmin) as $idTrilha) {
			$curso = LmsCurso::getByTrilha((int)$idTrilha, $idAdmin);
			if (!$curso instanceof LmsCurso || (int)$curso->publicado !== 1) {
				continue;
			}
			if (LmsCertificado::getByAlunoCurso($idAluno, (int)$curso->id) instanceof LmsCertificado) {
				continue;
			}
			self::emitirSeCursoCompleto($idAluno, $idAdmin, $curso);
		}
	}

	/**
	 * True se o aluno ainda está com 100% no curso deste certificado.
	 */
	public static function aindaValido(LmsCertificado $c, int $idAluno, int $idAdmin): bool {
		$curso = LmsCurso::getByIdAdmin((int)$c->id_curso, $idAdmin);
		if (!$curso instanceof LmsCurso) {
			return false;
		}
		$mapped = StudentApiMapper::course($curso, $idAluno, $idAdmin, false);
		return (int)($mapped['progressPercent'] ?? 0) >= 100;
	}

	public static function pdfApiPath(int $idCertificado): string {
		return '/certificates/'.$idCertificado.'/html';
	}

	/**
	 * @param array<string,mixed> $mapped
	 * @return array{titulo_curso:string,nome_escola:string,carga_h:int,modulos:string,id_trilha:int}
	 */
	private static function montarSnapshot(LmsCurso $curso, array $mapped, int $idAdmin): array {
		$idTrilha = (int)$curso->id_trilha;
		$trilha = Trilhas::getTrilhaById($idTrilha);
		$titulo = trim((string)($mapped['title'] ?? ''));
		if ($titulo === '' && $trilha) {
			$titulo = (string)$trilha->nome;
		}
		if ($titulo === '') {
			$titulo = 'Curso online';
		}

		$modulosNomes = [];
		foreach (LmsModulo::listByCurso((int)$curso->id, $idAdmin) as $mod) {
			$modulosNomes[] = (string)$mod->titulo;
		}
		$modulosTxt = $modulosNomes ? implode(', ', $modulosNomes) : $titulo;

		$nomeEscola = '';
		$escola = ClientesAssinantes::getEscolaById($idAdmin);
		if ($escola instanceof ClientesAssinantes) {
			$nomeEscola = trim((string)($escola->nome ?? ''));
		}

		return [
			'titulo_curso' => $titulo,
			'nome_escola' => $nomeEscola !== '' ? $nomeEscola : 'Escola',
			'carga_h' => $trilha ? (int)$trilha->carga_h : (int)($mapped['workloadHours'] ?? 0),
			'modulos' => $modulosTxt,
			'id_trilha' => $idTrilha,
		];
	}

	/** @param array{titulo_curso:string,nome_escola:string,carga_h:int,modulos:string,id_trilha:int} $snap */
	private static function snapshotDiferente(LmsCertificado $c, array $snap): bool {
		return (string)$c->titulo_curso !== $snap['titulo_curso']
			|| (string)$c->nome_escola !== $snap['nome_escola']
			|| (int)$c->carga_h !== (int)$snap['carga_h']
			|| (string)($c->modulos ?? '') !== $snap['modulos']
			|| (int)$c->id_trilha !== (int)$snap['id_trilha'];
	}

	/** @return array<string,mixed> */
	private static function formatItem(LmsCertificado $c, string $status, int $progress): array {
		$valid = $status === 'valid';
		return [
			'id' => (string)$c->id,
			'courseId' => (string)$c->id_curso,
			'courseTitle' => (string)$c->titulo_curso,
			'schoolName' => (string)$c->nome_escola,
			'issuedAt' => $c->conclusao ? date('c', strtotime((string)$c->conclusao)) : date('c'),
			'workloadHours' => (int)$c->carga_h,
			'code' => (string)($c->codigo ?? ''),
			'pdfUrl' => $valid ? self::pdfApiPath((int)$c->id) : null,
			'status' => $valid ? 'valid' : 'outdated',
			'progressPercent' => $progress,
		];
	}

	/**
	 * Base URL do site da escola para verificação do certificado comercial.
	 * @throws \RuntimeException se site não cadastrado
	 */
	public static function urlSiteEscolaParaCertificado(int $idAdmin): string {
		$escola = ClientesAssinantes::getEscolaById($idAdmin);
		$site = $escola instanceof ClientesAssinantes ? trim((string)($escola->site ?? '')) : '';
		if ($site === '') {
			throw new \RuntimeException(
				'Cadastre o site da escola em Configurações → Dados da escola para gerar o QR do certificado.'
			);
		}
		if (!preg_match('#^https?://#i', $site)) {
			$site = 'https://'.$site;
		}
		return rtrim($site, '/');
	}

	public static function urlVerificacaoComercial(int $idAdmin, string $codigo): string {
		return self::urlSiteEscolaParaCertificado($idAdmin).'/certificado?crt='.rawurlencode($codigo);
	}
}
