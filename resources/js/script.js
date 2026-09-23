

// Bolsista: valor 0, sem carnê; parcelas = duração do curso
function syncBolsistaMatricula() {
  var bolsista = document.getElementById('bolsista');
  var valor1 = document.getElementById('valor');
  var pont = document.getElementById('pontualidade');
  var tipo = document.getElementById('tipo_parcelamento');
  var hint = document.getElementById('hint-bolsista');
  if (!bolsista || bolsista.disabled) {
    return;
  }
  var on = !!bolsista.checked;
  if (on) {
    if (valor1) {
      valor1.value = '0';
      valor1.removeAttribute('required');
      valor1.setAttribute('readonly', 'readonly');
    }
    if (pont) {
      pont.checked = false;
      pont.disabled = true;
    }
    if (tipo) {
      tipo.value = 'Carnê Simples';
      tipo.disabled = true;
    }
    if (hint) {
      hint.textContent = 'Bolsista: nenhum débito será gerado. “Parcelas” = duração do curso em meses.';
    }
    if (typeof jQuery !== 'undefined') {
      $('#obs').text('Matrícula bolsista — sem carnê no caixa.');
    }
  } else {
    if (valor1) {
      valor1.removeAttribute('readonly');
      valor1.setAttribute('required', 'required');
    }
    if (tipo) {
      tipo.disabled = false;
    }
    if (hint) {
      hint.textContent = 'Com bolsa, o valor fica zerado e nenhuma parcela é lançada no caixa. As parcelas indicam só a duração do curso.';
    }
    if (typeof syncDescontoComTipoParcelamento === 'function') {
      syncDescontoComTipoParcelamento();
    }
  }
}

// Desconto pontualidade só no Carnê Simples (não no Mercado Pago / PIX)
function syncDescontoComTipoParcelamento() {
  var bolsista = document.getElementById('bolsista');
  if (bolsista && bolsista.checked) {
    return;
  }
  var tipo = document.getElementById('tipo_parcelamento');
  var checkbox = document.getElementById('pontualidade');
  var hint = document.getElementById('hint-desconto-pontualidade');
  var valor1 = document.getElementById('valor');
  if (!tipo || !checkbox) {
    return;
  }
  var comGateway = (tipo.value === 'Carnê com Pix');
  if (comGateway) {
    checkbox.checked = false;
    checkbox.disabled = true;
    if (hint) {
      hint.textContent = 'Indisponível com Carnê com Pix (Mercado Pago).';
    }
    if (typeof jQuery !== 'undefined') {
      $('#obs').text('');
    }
    if (valor1) {
      valor1.removeAttribute('readonly');
    }
  } else {
    checkbox.disabled = false;
    if (hint) {
      hint.textContent = 'Somente no Carnê Simples (10% até o vencimento).';
    }
    if (typeof checkPontualidade === 'function') {
      checkPontualidade();
    }
  }
}

// FUNÇÃO PARA DESCONTO PONTUALIDADE
function checkPontualidade() {
  let checkbox = document.getElementById("pontualidade");
  let valor1 = document.getElementById("valor");
  if (!checkbox || !valor1 || checkbox.disabled) {
    return;
  }
  let valor = parseFloat(valor1.value);

  let valor3 = valor * 90 / 100;
  valor3 = valor3.toFixed(2);
  let msg;

  if (valor3 > 0) {
    msg = 'obs: Desconto de 10% aplicado, na pontualidade o valor será R$' + valor3;

  } else {
    msg = 'Informe um valor válido para aplicação de desconto.';

  }

  if (checkbox.checked) {
    $('#obs').text(msg);
    valor1.setAttribute('readonly', 'readonly');
  } else {
    $('#obs').text('');
    valor1.removeAttribute('readonly', 'readonly');
  }
}

// 
function valorPagar() {
  var valor_pagar = document.getElementById('valor_pagar').value;

     // Regex para permitir apenas números, vírgulas e pontos
  var regex = /^[0-9.,]+$/;

    // Limpa qualquer alerta anterior
  document.getElementById('response').innerHTML = '';

  if(valor_pagar !=''){
    if (!regex.test(valor_pagar)) {
        // Exibe o alerta se houver caracteres inválidos
      document.getElementById('response').innerHTML = `
      <div class="alert alert-warning alert-dismissible fade show" role="alert">
      <strong>Opps!</strong> Campo Valor recebido só aceita valores numéricos.
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>`;
        document.getElementById('valor_pagar').value = ''; // Limpa o campo Troco
        return;
      }
    }
    
    calcularTroco()
  }

// CALCULA TROCO
  function calcularTroco() {

    var valorRecebido = document.getElementById('valor_recebido').value;

    // Regex para permitir apenas números, vírgulas e pontos
    var regex = /^[0-9.,]+$/;

    // Limpa qualquer alerta anterior
    document.getElementById('response').innerHTML = '';

    if(valorRecebido !=''){
      if (!regex.test(valorRecebido)) {
        // Exibe o alerta se houver caracteres inválidos
        document.getElementById('response').innerHTML = `
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <strong>Opps!</strong> Campo Valor recebido só aceita valores numéricos.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>`;
        document.getElementById('troco').value = ''; // Limpa o campo Troco
        return;
      }
    }

    // Converte o valor recebido (vírgula para ponto)
    var valorRecebidoFloat = parseFloat(valorRecebido.replace(",", "."));

    // Pega o valor a pagar e converte também
    var valorPagar = parseFloat(document.getElementById('valor_pagar').value.replace(",", "."));

    // Calcula o troco se o valor for válido
    if (!isNaN(valorRecebidoFloat) && valorRecebidoFloat >= valorPagar) {
     var troco = valorRecebidoFloat - valorPagar;
     document.getElementById('troco').value = troco.toFixed(2).replace(".", ",");
   } else {
     document.getElementById('troco').value = '';
   }
 }



// TERMOS DE USO
 function ativaBtn() {
   var auth = document.getElementById('termo_uso').checked;
   var btnTermo = document.getElementById('btn-termo');
   if (auth) {
     btnTermo.removeAttribute('disabled');
   } else {
     btnTermo.setAttribute('disabled', 'disabled');
   }
 }

 function termos(id_user) {

   event.preventDefault();
   $.ajax({
     url: url_base+aceitaTermo,
     method: "post",
     data: {},
     dataType: "text",
     success: function(result) {
      if (String(result).trim() === '1' || result == true) {
       location.href = url_base + 'painel';
     }
   },
 });
 }

// FUNÇÃO PARA RESETAR SENHA
 function resetSenha(id=null) { 

  var senha1 = document.getElementById("senha1").value;
  var senha2 = document.getElementById("senha2").value;

  $.ajax({
   type: 'POST',
   url: url_base+resetarSenha,
   data: {id,senha1,senha2},
   success: function(e) {
 
    if(e == 1){

      $('#btn-fechar').click();

      Swal.fire({
        title: "Senha atualizada!",
        text: "A sua senha foi atualizada com sucesso.",
        icon: "success"
      });
      

    } else {
      
      $("#response").html('<div class="alert alert-danger">' + e + '</div>');
    }
  }
})

}



function displaySelectedImage(event, elementId) {

  const selectedImage = document.getElementById(elementId);
  const fileInput = event.target;

  if (fileInput.files && fileInput.files[0]) {
    const reader = new FileReader();

    reader.onload = function(e) {
      selectedImage.src = e.target.result;
    };

    reader.readAsDataURL(fileInput.files[0]);
  }
}



function perfil() {

 $.ajax({
   url: url_base+perfilEdicao,
   dataType: "json",
   success: function(result) {

    if (typeof result === 'object' && result !== null) {

      $('#body_perfil').html(result.form);
      if (result.cidade) {
        selectEstado(result.cidade);
      }

      $('#modalPerfil').modal('show');
    } else {

     Swal.fire({
      title: "Ops!",
      text: "Algo errado não está certo.",
      icon: "error"
    });

   }
   
 },
});


}


// FUNÇÃO QUE CARREGA AS CIDADES EM SELECT
function selectEstado(cidade) {
  var estado = document.getElementById("estado").value;
  
  $.ajax({
   type: 'POST',
   url: url_base+estadoCidades,
   data: {estado,cidade},
   success: function(e) {
    document.getElementById("cidades").innerHTML = e;
  }
})
  
}


// FUNÇÃO QUE EXECUTA UM CREATE OU UPDATE DE DADOS
$(document).on("submit", "#formPerfil", function(event) {
    event.preventDefault(); // Evita o envio do formulário de forma tradicional

    $.ajax({
        url: url_base + saveProfile,
        type: "POST",
        dataType: "json", // Espera que o servidor retorne um JSON
        data: $(this).serialize(), // Serializa os dados do formulário
        success: function(response) {

            console.log(response)

            if (response.erro) {
                $("#response").html('<div class="alert alert-danger">' + response.erro + '</div>');

            } else {
                $('#btn-fechar').click();

                Swal.fire({
                    title: "Óttimo!",
                    text: "Os dados foram atualizados com sucesso.",
                    icon: "success"
               });

            }
        },
        error: function(xhr, status, error) {
            // Lida com erros de requisição
            $("#response").html('<div class="alert alert-danger">Ocorreu um erro ao processar a solicitação.</div>');
            console.log("Erro:", error);
        }
    });
});