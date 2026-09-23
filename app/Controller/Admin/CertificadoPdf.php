<?php 

namespace App\Controller\Admin;

use \App\Utils\View;
use \App\Model\Entity\Trilhas as EntityTrilhas;
use \App\Model\Entity\User as EntityUser;
use \App\Model\Entity\Certificados as EntityCertificados;
use \App\Model\Entity\ClientesAssinantes;
use \App\Session\User\Login as SessionUser;
use \App\Common\Helpers\TenantHelper;
use \App\Common\Helpers\BrandingHelper;

class CertificadoPdf extends Page {

    /**
     * Método responsável por retornar o formulário/página do certificado
     */
    public static function index($request, $id) {
        $id = (int)$id;
        $id_admin = parent::getIdAdminInt();

        if (!TenantHelper::pertence('certificados', $id, $id_admin)) {
            return 'Certificado não encontrado.';
        }

        $dados = self::geraCertPdf($id);

        return View::render('admin/modules/certificados/certificado', [
            'img-qrcode' => $dados['img_qrcode'],
            'script'     => $dados['script']
        ]);
    }

    /**
     * Método responsável por processar os dados e gerar os caminhos do certificado
     */
    public static function geraCertPdf($id) {
        // Busca os dados do certificado
        $dados = (array) EntityCertificados::getCertificadoById($id);

        $codigo          = $dados['codigo'];
        $id_aluno       = $dados['id_aluno'];
        $id_trilha      = $dados['id_trilha'];
        $descricao      = 'Composto pelos módulos ' . $dados['modulos'];
        $hora           = 'Perfazendo a carga horária total de ' . $dados['carga_h'] . ' horas';
        $dataDeConclusao = 'Concluído em ' . $dados['conclusao'];

        // Busca dados do aluno
        $obUser = (array) EntityUser::getUserById($id_aluno);
        $nome   = $obUser['nome'] ?? '';

        // Busca dados da trilha/curso
        $dadosTrilha = (array) EntityTrilhas::getTrilhaById($id_trilha);
        $curso       = $dadosTrilha['nome'] ?? '';

        // Modelo de fundo por escola (já personalizado com logo)
        $modeloArquivo = null;
        $idAdminCert = (int)($dados['id_admin'] ?? 0);
        if ($idAdminCert <= 0) {
            $sess = SessionUser::getUserLogedData();
            $idAdminCert = (int)($sess['escola']['id'] ?? $sess['usuario']['id_admin'] ?? 0);
        }
        if ($idAdminCert > 0 && ClientesAssinantes::temColunaModeloCertificado()) {
            $escola = ClientesAssinantes::getEscolaById($idAdminCert);
            if ($escola instanceof ClientesAssinantes) {
                $modeloArquivo = $escola->modelo_certificado ?? null;
            }
        }
        $modeloCertUrl = BrandingHelper::urlModeloCertificado($modeloArquivo);
        $modeloCertJs  = json_encode($modeloCertUrl, JSON_UNESCAPED_SLASHES);

        $fraseConclusao = 'Concluiu com louvor o curso de';
        if ($idAdminCert > 0 && ClientesAssinantes::temColunaCertificadoFrase()) {
            $escolaFrase = isset($escola) && $escola instanceof ClientesAssinantes
                ? $escola
                : ClientesAssinantes::getEscolaById($idAdminCert);
            if ($escolaFrase instanceof ClientesAssinantes) {
                $f = trim((string)($escolaFrase->certificado_frase_conclusao ?? ''));
                if ($f !== '') {
                    $fraseConclusao = $f;
                }
            }
        }
        $fraseConclusaoJs = json_encode($fraseConclusao, JSON_UNESCAPED_UNICODE);

        // Escape seguro para strings no JS
        $nomeJs = json_encode((string)$nome, JSON_UNESCAPED_UNICODE);
        $cursoJs = json_encode((string)$curso, JSON_UNESCAPED_UNICODE);
        $descricaoJs = json_encode((string)$descricao, JSON_UNESCAPED_UNICODE);
        $dataJs = json_encode((string)$dataDeConclusao, JSON_UNESCAPED_UNICODE);
        $horaJs = json_encode((string)$hora, JSON_UNESCAPED_UNICODE);
        $codigoJs = json_encode((string)$codigo, JSON_UNESCAPED_UNICODE);

        // QR aponta para o site da escola (perfil), não SITE do .env
        try {
            $verifyUrl = \App\Common\Helpers\LmsCertificadoHelper::urlVerificacaoComercial($idAdminCert, (string)$codigo);
        } catch (\Throwable $e) {
            throw new \Exception($e->getMessage());
        }
        $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data='.rawurlencode($verifyUrl);

        // --- LÓGICA DE CAMINHOS FÍSICOS (SISTEMA) ---
        $caminhoProjeto = realpath(__DIR__ . '/../../../'); 
        $diretorioImg   = '/uploads/img/certificado/';
        $caminhoSalvar  = $caminhoProjeto . $diretorioImg;

        if (!is_dir($caminhoSalvar)) {
            mkdir($caminhoSalvar, 0755, true);
        }

        $nomeArquivoQr   = 'qr_' . $codigo . '.png';
        $caminhoCompleto = $caminhoSalvar . $nomeArquivoQr;

        $imgData = @file_get_contents($qrCodeUrl);
        if ($imgData === false || file_put_contents($caminhoCompleto, $imgData) === false) {
            throw new \Exception("Erro ao salvar a imagem QR Code em: " . $caminhoCompleto);
        }

        $img_qrcode = '<img id="qrCodeImg" src="' . URL . $diretorioImg . $nomeArquivoQr . '" style="display:none;">';
        $baseUrl    = URL;
        $qrUrlJs    = json_encode($baseUrl . $diretorioImg . $nomeArquivoQr, JSON_UNESCAPED_SLASHES);

$script = <<<SCRIPT
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script>
    console.log("Iniciando script de geração de certificado...");

    const canvas = document.getElementById("canvas");
    const ctx = canvas.getContext("2d");

    canvas.width = 842; 
    canvas.height = 595; 

    const name = {$nomeJs};
    const curso = {$cursoJs};
    const descricao = {$descricaoJs};
    const dataDeConclusao = {$dataJs};
    const hora = {$horaJs};
    const codigoCert = {$codigoJs};

    const certImage = new Image();
    const qrCodeImg = new Image();
    let imagensCarregadas = 0;
    const totalImagens = 2;

    function aoCarregarImagem() {
        imagensCarregadas++;
        if (imagensCarregadas === totalImagens) {
            draw();
        }
    }

    certImage.onerror = () => console.error("Erro ao carregar o fundo do certificado.");
    qrCodeImg.onerror = () => console.error("Erro ao carregar a imagem do QR Code.");

    certImage.onload = aoCarregarImagem;
    qrCodeImg.onload = aoCarregarImagem;

    certImage.src = {$modeloCertJs};
    qrCodeImg.src = {$qrUrlJs};

    function draw() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(certImage, 0, 0, canvas.width, canvas.height);
        ctx.drawImage(qrCodeImg, 50, 470, 80, 80);

        ctx.fillStyle = "black";
        ctx.textAlign = "center";

        ctx.font = "bold italic 26pt Arial";
        ctx.fillText(name, 421, 280);

        ctx.font = "normal 16pt Arial";
        ctx.fillText({$fraseConclusaoJs}, 421, 325);
        
        ctx.font = "bold 18pt Arial";
        ctx.fillText(curso, 421, 355);

        ctx.font = "normal 12pt Arial";
        const maxWidth = 650;
        const lineHeight = 25;
        let x = 421;
        let y = 390;
        let line = "";
        const words = descricao.split(" ");

        for (const word of words) {
            const testLine = line + word + " ";
            const metrics = ctx.measureText(testLine);
            if (metrics.width > maxWidth && line !== "") {
                ctx.fillText(line, x, y);
                line = word + " ";
                y += lineHeight;
            } else {
                line = testLine;
            }
        }
        ctx.fillText(line, x, y);

        ctx.font = "italic 11pt Arial";
        ctx.textAlign = "left";
        ctx.fillText(hora, 500, 480);
        ctx.fillText(dataDeConclusao, 500, 500);
        
        ctx.font = "8pt Arial";
    }

    document.getElementById("dpdf").addEventListener("click", function () {
        const { jsPDF } = window.jspdf;
        const imgData = canvas.toDataURL("image/jpeg", 1.0);
        const pdf = new jsPDF("l", "mm", "a4");
        pdf.addImage(imgData, "JPEG", 0, 0, 297, 210);
        pdf.save("certificado_" + codigoCert + ".pdf");
    });

    document.getElementById("dimg").addEventListener("click", function () {
        const imgData = canvas.toDataURL("image/png", 1.0);
        const link = document.createElement("a");
        link.download = "certificado_" + codigoCert + ".png";
        link.href = imgData;
        link.click();
    });
</script>
SCRIPT;

        return [
            'img_qrcode' => $img_qrcode,
            'script'     => $script,
        ];
    }
}
