<?php

namespace App\Controller\Admin;

use App\Utils\View;
use App\Model\Entity\User as EntityUser;
use App\Model\Entity\LmsCertificado;
use App\Common\Helpers\BrandingHelper;
use App\Model\Entity\ClientesAssinantes;

/**
 * Certificado simbólico do portal EAD (sem QR).
 */
class CertificadoEadPdf {

	public static function geraHtml(LmsCertificado $cert): string {
		$aluno = EntityUser::getUserById((int)$cert->id_aluno);
		$nome = $aluno ? (string)$aluno->nome : 'Aluno';
		$curso = (string)$cert->titulo_curso;
		$escola = (string)$cert->nome_escola;
		$descricao = 'Composto pelos módulos '.(string)$cert->modulos;
		$hora = 'Perfazendo a carga horária total de '.(int)$cert->carga_h.' horas';
		$dataDeConclusao = 'Concluído em '.(string)$cert->conclusao;
		$codigo = (string)$cert->codigo;

		$modeloArquivo = null;
		$idAdmin = (int)$cert->id_admin;
		if ($idAdmin > 0 && ClientesAssinantes::temColunaModeloCertificado()) {
			$esc = ClientesAssinantes::getEscolaById($idAdmin);
			if ($esc instanceof ClientesAssinantes) {
				$modeloArquivo = $esc->modelo_certificado ?? null;
			}
		}
		$modeloCertJs = json_encode(BrandingHelper::urlModeloCertificado($modeloArquivo), JSON_UNESCAPED_SLASHES);

		$frase = 'Concluiu com aproveitamento o curso de';
		if ($idAdmin > 0 && ClientesAssinantes::temColunaCertificadoFrase()) {
			$esc = isset($esc) && $esc instanceof ClientesAssinantes
				? $esc
				: ClientesAssinantes::getEscolaById($idAdmin);
			if ($esc instanceof ClientesAssinantes) {
				$f = trim((string)($esc->certificado_frase_conclusao ?? ''));
				if ($f !== '') {
					$frase = $f;
				}
			}
		}

		$nomeJs = json_encode($nome, JSON_UNESCAPED_UNICODE);
		$cursoJs = json_encode($curso, JSON_UNESCAPED_UNICODE);
		$escolaJs = json_encode($escola, JSON_UNESCAPED_UNICODE);
		$descricaoJs = json_encode($descricao, JSON_UNESCAPED_UNICODE);
		$dataJs = json_encode($dataDeConclusao, JSON_UNESCAPED_UNICODE);
		$horaJs = json_encode($hora, JSON_UNESCAPED_UNICODE);
		$codigoJs = json_encode($codigo, JSON_UNESCAPED_UNICODE);
		$fraseJs = json_encode($frase, JSON_UNESCAPED_UNICODE);

		$script = <<<SCRIPT
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script>
    const canvas = document.getElementById("canvas");
    const ctx = canvas.getContext("2d");
    canvas.width = 842;
    canvas.height = 595;

    const name = {$nomeJs};
    const curso = {$cursoJs};
    const escola = {$escolaJs};
    const descricao = {$descricaoJs};
    const dataDeConclusao = {$dataJs};
    const hora = {$horaJs};
    const codigoCert = {$codigoJs};

    const certImage = new Image();
    certImage.onload = draw;
    certImage.onerror = () => {
        ctx.fillStyle = "#f8fafc";
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        drawTexts();
    };
    certImage.src = {$modeloCertJs};

    function draw() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(certImage, 0, 0, canvas.width, canvas.height);
        drawTexts();
    }

    function drawTexts() {
        ctx.fillStyle = "black";
        ctx.textAlign = "center";

        ctx.font = "bold italic 26pt Arial";
        ctx.fillText(name, 421, 270);

        ctx.font = "normal 16pt Arial";
        ctx.fillText({$fraseJs}, 421, 315);

        ctx.font = "bold 18pt Arial";
        ctx.fillText(curso, 421, 345);

        ctx.font = "italic 12pt Arial";
        ctx.fillText(escola, 421, 375);

        ctx.font = "normal 11pt Arial";
        const maxWidth = 650;
        const lineHeight = 22;
        let y = 405;
        let line = "";
        const words = descricao.split(" ");
        for (const word of words) {
            const testLine = line + word + " ";
            if (ctx.measureText(testLine).width > maxWidth && line !== "") {
                ctx.fillText(line, 421, y);
                line = word + " ";
                y += lineHeight;
            } else {
                line = testLine;
            }
        }
        ctx.fillText(line, 421, y);

        ctx.font = "italic 11pt Arial";
        ctx.textAlign = "left";
        ctx.fillText(hora, 500, 500);
        ctx.fillText(dataDeConclusao, 500, 520);

        ctx.font = "8pt Arial";
        ctx.fillText("Cód. " + codigoCert, 50, 540);
    }

    document.getElementById("dpdf").addEventListener("click", function () {
        const { jsPDF } = window.jspdf;
        const imgData = canvas.toDataURL("image/jpeg", 1.0);
        const pdf = new jsPDF("l", "mm", "a4");
        pdf.addImage(imgData, "JPEG", 0, 0, 297, 210);
        pdf.save("certificado_ead_" + codigoCert + ".pdf");
    });

    document.getElementById("dimg").addEventListener("click", function () {
        const imgData = canvas.toDataURL("image/png", 1.0);
        const link = document.createElement("a");
        link.download = "certificado_ead_" + codigoCert + ".png";
        link.href = imgData;
        link.click();
    });
</script>
SCRIPT;

		return View::render('admin/modules/certificados/certificado', [
			'img-qrcode' => '',
			'script' => $script,
		]);
	}
}
