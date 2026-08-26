# KB Wizard — Passo a Passo para Base de Conhecimento (GLPI 11.06)

Transforma qualquer artigo da **Base de Conhecimento** em um **guia guiado passo a passo** para que técnicos novos não se percam no texto corrido.

## ✨ O que o plugin faz

- Adiciona botão **“Iniciar Passo a Passo”** no topo do artigo (quando ativado).
- Exibe o conteúdo em **modal guiado**: 1 passo por vez, com **barra de progresso**, **lista lateral**, navegação **Anterior/Próximo**, atalhos de teclado (← → / ESC) e botão **Concluir**.
- Salva **progresso por usuário** (continuar de onde parou).
- Dois modos:
  - **Automático**: divide o campo `answer` do artigo automaticamente. Critérios: `<hr>` , `<h2>` , `<hr>` ou `<h2>` (padrão) ou marcador `---PASSO---`.
  - **Manual**: você cadastra passos com título + conteúdo rico (HTML) em ordem definida.
- Aba **“Passo a Passo”** dentro do artigo (visível para quem tem permissão `UPDATE` na base de conhecimento) para ativar e configurar.
- Fallback inteligente: se modo manual estiver vazio, usa automático.
- Design responsivo e acessível, foco total no passo atual.

## 📦 Instalação (GLPI 11.0.x - testado em 11.06)

1. Copie a pasta `kbwizard` para `glpi/plugins/` ou `glpi/marketplace/` (depende da sua instalação):
   ```
   glpi/
   └── plugins/
       └── kbwizard/
           ├── setup.php
           ├── css/
           ├── js/
           └── ...
   ```
   *Em algumas instalações GLPI 11 o diretório correto é `marketplace/kbwizard`.*

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

Desinstalar remove as tabelas.

## 🎨 Customização
- `css/kbwizard.css` — cores, radius, layout. Altere `--kbwizard-primary` se quiser.
- `js/kbwizard.js` — lógica; expõe `KBWizard` global (`open`, `close`, `next`, `prev`, `goTo`, `reset`).
- Template do banner/modal: `templates/wizard_banner.html.php`.

## ❓ FAQ

**O wizard aparece para visitantes anônimos?** Aparece, mas progresso só é salvo para usuários logados.

**Posso deixar navegação sequencial obrigatória?** Sim: na aba de config, desmarque “Permitir navegar livremente”. Ainda não força `require_sequential` visualmente (futuro), mas o JS já respeita.

**Tenho 10 artigos antigos sem `<hr>`.** Use modo `hr_h2` (detecta ambos) ou clique em “Gerar a partir do artigo” no modo manual e ajuste.

## 📋 Compatibilidade
- GLPI `11.0` → `11.1` (testado em **11.06**)
- PHP `>= 8.2`
- Não requer dependências composer.

## 📝 Licença
GPL-3.0-or-later. Sinta-se livre para adaptar para sua rede (GPLI).

## 👨‍💻 Créditos
Feito para facilitar onboarding de técnicos — menos “se perder no guia completo”, mais fluxo guiado.
