# KB Wizard — Passo a Passo para Base de Conhecimento (GLPI 11.0 a 12.0)

Transforma qualquer artigo da **Base de Conhecimento** em um **guia guiado passo a passo** para que técnicos novos não se percam no texto corrido.

## ✨ O que o plugin faz

- Adiciona botão **“Iniciar Passo a Passo”** no topo do artigo (quando ativado).
- Exibe o conteúdo em **modal guiado**: 1 passo por vez, com **barra de progresso**, **lista lateral**, navegação **Anterior/Próximo**, atalhos de teclado (← → / ESC) e botão **Concluir**.
- Salva **progresso por usuário** (continuar de onde parou).
- **Exportar PDF**: gera o artigo inteiro em PDF (HTML original preservado, apenas as linhas `---PASSO---` são removidas) com acentuação correta — ideal para anexar em ferramentas como o NotebookLM.
- **Sincronização com Google Drive** (opcional): espelha **todos os artigos** em um Shared Drive como **Google Doc + PDF**, atualizando sozinho quando o artigo muda — o Google Doc é o que o NotebookLM sincroniza automaticamente.
- Dois modos:
  - **Automático**: divide o campo `answer` do artigo automaticamente. Critérios: `<hr>` , `<h2>` , `<hr>` ou `<h2>` (padrão) ou marcador `---PASSO---`.
  - **Manual**: você cadastra passos com título + conteúdo rico (HTML) em ordem definida.
- Aba **“Passo a Passo”** dentro do artigo (visível para quem tem permissão `UPDATE` na base de conhecimento) para ativar e configurar.
- Fallback inteligente: se modo manual estiver vazio, usa automático.
- Design responsivo e acessível, foco total no passo atual.

## 📦 Instalação (GLPI 11.0 a 12.0 - testado em 11.06)

1. Copie a pasta `kbwizard` para `glpi/plugins/` ou `glpi/marketplace/` (depende da sua instalação):
   ```
   glpi/
   └── plugins/
       └── kbwizard/
           ├── setup.php
           ├── public/
           │   ├── css/
           │   └── js/
           └── ...
   ```
   *Em algumas instalações GLPI 11 o diretório correto é `marketplace/kbwizard`.*
   *Copie a pasta COMPLETA: os assets ficam em `public/` com css/js dentro — cópias parciais de apenas `css/` + `js/` não funcionam mais.*

2. No GLPI: **Configurar > Plugins** → encontre **KB Wizard - Passo a Passo** → **Instalar** → **Ativar**.

3. Permissões: o plugin usa a mesma permissão da Base de Conhecimento (`knowbase`). Usuários com `Leitura` veem o wizard; com `Atualizar` configuram.

## 🚀 Como usar

### Ativar no artigo
1. Abra **Base de Conhecimento > artigo desejado**.
2. Vá na aba **Passo a Passo** (ícone `ti-list-check`).
3. Marque **Ativar modo Passo a Passo neste artigo**.
4. Escolha **Modo automático** (recomendado para começar) ou **Manual**.
   - Automático: selecione o critério. Dica: no editor do artigo insira **linha horizontal** (`<hr>`) onde cada passo deve terminar. Alternativa: escreva `---PASSO---` em uma linha.
   - Manual: salve, depois use a seção **Passos Manuais** para criar/gerar passos.
5. **Salvar**.

### Modo Manual detalhado
- Na aba, seção **Passos Manuais** → **Adicionar passo** (título + conteúdo).
- Ou clique **Gerar a partir do artigo** para converter o conteúdo atual em passos editáveis automaticamente.

### Para o leitor (técnico)
- No topo do artigo aparece o **banner azul** com **Iniciar Passo a Passo**.
- Ao iniciar, abre o **modal**: progresso, contador (ex: 2/5), lista lateral.
- Navegue com botões ou clique nos passos laterais (se permitido).
- Ao final, **Concluir** → feedback verde. Progresso é salvo; ao voltar verá **Continuar de onde parei**.

### Exportar para PDF (NotebookLM)
1. No banner azul do artigo, clique em **Exportar PDF**.
2. Abre uma página limpa com **o artigo completo e sua formatação original** (títulos, listas, tabelas, imagens). As linhas `---PASSO---` são removidas automaticamente.
3. Na janela de impressão, escolha **Salvar como PDF** e confirme.
4. O nome do arquivo já vem como `<título do artigo> - Passo a Passo`. Acentos, imagens e texto selecionável são preservados, então o NotebookLM extrai o conteúdo corretamente.

> O PDF é gerado pelo navegador (não pelo servidor): nenhuma biblioteca extra é necessária e a fidelidade visual é a mesma da tela.

## ☁️ Sincronização automática com Google Drive (NotebookLM)

O NotebookLM só atualiza sozinho fontes que sejam **Google Docs nativos** — PDF importado continua sendo um snapshot. Por isso o plugin publica cada artigo **duas vezes** no Drive: um **Google Doc** (a fonte que o NotebookLM sincroniza) e um **PDF** (consulta/impressão). Os dois são atualizados no mesmo `fileId`, então o link nunca muda.

Há três modos de autenticação, escolhidos na própria tela de configuração:

- **Conta Google — HTTPS** — para GLPI com URL pública `https://`. Fluxo OAuth padrão com redirect URI.
- **Conta Google — código** — **recomendado para GLPI interno** (`http://` ou IP). Usa *device flow*: o Google recusa redirect URI em http/IP, e esse modo não usa redirect — você abre `google.com/device`, digita um código e autoriza. Também não exige acesso de fora para o GLPI.
- **Service account** — para quem tem **Google Workspace** e um **Shared Drive**.

Nos dois modos de conta Google os arquivos ficam no **Meu Drive** da conta conectada (15 GB) e o escopo é `drive.file`: o plugin só enxerga o que ele mesmo criou.

### Pré-requisitos (modos conta Google)
1. Uma conta Google (pode ser criada só para o plugin).
2. Projeto no Google Cloud com a **Drive API** habilitada (grátis, sem faturamento).
3. `url_base` do GLPI configurado (Configurar > Geral) — é a URL que o Google usa para baixar as imagens.

### Configuração — Conta Google por código (GLPI interno)
1. **Google Cloud > Credentials > Create credentials > OAuth client ID** → tipo **"TVs and Limited Input devices"** (não é Web application).
2. Copie **Client ID** e **Client secret**.
3. Em **OAuth consent screen**, publique o app (**Em produção**).
4. No GLPI: **Configurar > Plugins > KB Wizard** → **Autenticação** → **Conta Google — código**.
5. Cole Client ID + secret, **Salvar** e clique em **Gerar código de conexão**.
6. Abra `https://www.google.com/device` em qualquer navegador, entre com a conta do plugin e digite o código exibido.
7. Volte à tela e clique em **Já autorizei**. O plugin salva o `refresh_token` e mostra o e-mail conectado.
8. **Testar conexão** → **Salvar**.

### Configuração — Conta Google com HTTPS
1. **Google Cloud > Credentials > OAuth client ID** → tipo **Web application** + redirect URI exibido na tela do plugin (precisa ser `https://`; o Google só aceita `http://localhost`).
2. Cole **Client ID** + **Client secret** na tela do plugin.
3. **Conectar conta Google** → autorize (aviso de app não verificado é esperado: *Avançado → Acessar*).
4. **Testar conexão** → **Salvar**.

> Publish o app como **"Em produção"** no OAuth consent screen. Em "Testing", o `refresh_token` expira em **7 dias** e a sincronização para.

### Alternativa: service account + Shared Drive (Workspace)
1. Google Cloud → **Drive API** → criar **service account** → chave **JSON**.
2. Criar um **Shared Drive** e adicionar o e-mail da service account como **Gerente de conteúdo**.
3. Na tela do plugin, escolher **Service account**, enviar o JSON, informar o **ID do Shared Drive**
   (`drive.google.com/drive/folders/<ID>` — o ID do drive, não de uma pasta interna).
4. **Testar conexão** → **Salvar** → **Sincronizar todos**.

### Como funciona
- Salvar um artigo (ou um passo manual) coloca o artigo na fila.
- O cron gera/atualiza o Google Doc, exporta o PDF pelo próprio Google e grava o estado.
- Excluir o artigo em definitivo move Doc/PDF para a lixeira do Drive.
- Falhas aparecem na tela de configuração; **Reprocessar erros** reenfileira os itens.

> Imagens anexadas ao artigo são servidas por URL assinada de curta duração (`front/driveimage.php`), porque o Google as baixa sem sessão. O token é vinculado ao artigo e expira em 30 minutos.

## 🛠️ Dicas de autoria

Escreva seu guia normalmente e use separadores:

**Exemplo com `<hr>` (recomendado):**
```html
<h2>Passo 1 - Acessar o servidor</h2>
<p>Acesse ...</p>
<hr>
<h2>Passo 2 - Executar backup</h2>
<p>Execute ...</p>
<hr>
<h2>Passo 3 - Validar</h2>
<p>Valide ...</p>
```

**Exemplo com marcador:**
```
Instruções iniciais...
---PASSO---
Configurar rede...
---PASSO---
Testar conectividade...
```

**Bom passo = uma ação verificável.** Use listas, prints, comandos em `<code>`.

## 🗃️ Tabelas criadas
- `glpi_plugin_kbwizard_configs` — config por artigo
- `glpi_plugin_kbwizard_steps` — passos manuais
- `glpi_plugin_kbwizard_progress` — progresso do usuário
- `glpi_plugin_kbwizard_drive_config` — configuração da sincronização com o Drive
- `glpi_plugin_kbwizard_drive_files` — fila/estado (fileId do Doc e do PDF, hash, erros)

Desinstalar remove as tabelas.

## 🎨 Customização
- `public/css/kbwizard.css` — cores, radius, layout. Altere `--kbwizard-primary` se quiser.
- `public/js/kbwizard.js` — lógica; expõe `KBWizard` global (`open`, `close`, `next`, `prev`, `goTo`, `reset`).
- Template do banner/modal: `templates/wizard_banner.html.php`.

## ❓ FAQ

**O wizard aparece para visitantes anônimos?** Aparece, mas progresso só é salvo para usuários logados.

**Posso deixar navegação sequencial obrigatória?** Sim: na aba de config, desmarque “Permitir navegar livremente”. Ainda não força `require_sequential` visualmente (futuro), mas o JS já respeita.

**Tenho 10 artigos antigos sem `<hr>`.** Use modo `hr_h2` (detecta ambos) ou clique em “Gerar a partir do artigo” no modo manual e ajuste.

## 📋 Compatibilidade
- GLPI `11.0` → `12.0` (migrado para 12.0; testado em **11.06**)
- PHP `>= 8.2`
- Não requer dependências composer.

## 📝 Licença
GPL-3.0-or-later. Sinta-se livre para adaptar para sua rede (GPLI).

## 👨‍💻 Créditos
Feito para facilitar onboarding de técnicos — menos “se perder no guia completo”, mais fluxo guiado.
