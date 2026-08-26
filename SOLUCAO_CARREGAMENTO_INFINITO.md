# Solu��o - Carregamento Infinito (GLPI 11.06) - KB Wizard 1.0.1

## Causa raiz
O loader do GLPI (`#loading` overlay) ficou preso por 3 motivos combinados:

1. **`setup.php` fatal**: `Plugin::getPhpDir('kbwizard')` chamado dentro do `post_show_item` sem fallback. Se o plugin estiver em `marketplace/` em vez de `plugins/`, ou se o DB ainda n�o tiver tabelas, `getFromDBByCrit` lan�ava exce��o n�o capturada. O GLPI n�o completa o HTML ? loader nunca some. **FIX 1.0.1**: `setup.php:76` agora usa `__DIR__ . '/templates/...'` + `try/catch` + `tableExists` antes de qualquer query (`setup.php:86-113`).

2. **`MutationObserver` no `document.documentElement`**: `js/kbwizard.js:352` observava **todo o DOM** com `subtree:true`. No GLPI 11 o layout recarrega abas via AJAX constantemente, disparando o observer em loop, re-bindando eventos e consumindo CPU ? p�gina parece travada. **FIX**: removido observer, substitu�do por `setInterval` leve (12x a cada 800ms) + listener `ajaxComplete` (`js/kbwizard.js:420-435`).

3. **`inc/config.class.php` e `inc/step.class.php` sem `tableExists`**: se o plugin foi copiado mas **n�o instalado**, qualquer `getStepsForItem` fazia `$DB->request` na tabela inexistente ? `Table doesn't exist` fatal que abortava o rendering da aba. **FIX**: guard `if (!$DB->tableExists(...)) return parseAnswer...` (`inc/step.class.php:18`, `inc/config.class.php:28`).

Outros ajustes:
- `setup.php:9` e `hook.php:15` agora com `try/catch` em `Migration` e `executeMigration`.
- `ajax/progress.php:1` antes fazia `Session::checkCSRF` que em falha retornava p�gina HTML (n�o JSON) e o `fetch` ficava sem resposta. Agora retorna JSON com 403 e fallback para `localStorage` se sem CSRF (`js/kbwizard.js:250`).
- `add_css`/`add_javascript` mudado de array `['css/...']` para string `'css/...'` (`setup.php:60`) - GLPI 11 espera string, array vazio causava 404 silencioso em algumas instala��es.

## Como atualizar (sem perder passos manuais)
```powershell
# 1. Pare o servidor web ou mantenha, mas desative o plugin primeiro
# No GLPI: Configurar > Plugins > KB Wizard > Desativar (N�O Desinstalar se tiver passos manuais)

# 2. Substitua os arquivos (j� feito em E:\GPLI\Plugin\kbwizard - vers�o 1.0.1)
# Se j� copiou para o servidor, recopie:

# Ex: se GLPI em C:\xampp\htdocs\glpi ou /var/www/html/glpi
# Windows (PowerShell):
Copy-Item -Recurse -Force "E:\GPLI\Plugin\kbwizard\*" "C:\xampp\htdocs\glpi\plugins\kbwizard\"
# ou se usa marketplace:
Copy-Item -Recurse -Force "E:\GPLI\Plugin\kbwizard\*" "C:\xampp\htdocs\glpi\marketplace\kbwizard\"

# Linux:
# sudo cp -r /mnt/e/GPLI/Plugin/kbwizard/* /var/www/html/glpi/marketplace/kbwizard/
# sudo chown -R www-data:www-data /var/www/html/glpi/marketplace/kbwizard
```

3. **Limpe cache** (obrigat�rio no GLPI 11):
   - No GLPI: **Configurar > Manuten��o > Limpar cache** ou `php bin/console cache:clear`
   - No navegador: `Ctrl+F5` na p�gina do artigo
   - Delete `files/_cache/*` manualmente se precisar

4. Reative o plugin: **Configurar > Plugins > KB Wizard > Ativar**. Se o bot�o for **Instalar**, clique em Instalar (ele cria as tabelas sem apagar se j� existirem).

5. Teste: abra um artigo ? aba **Passo a Passo** deve abrir instantaneamente. Se ainda travar, veja logs.

## Debug se ainda travar
1. **Logs GLPI**: `glpi/files/_log/php-errors.log` e `glpi/files/_log/kbwizard.log` (novo em 1.0.1)
2. **Console do navegador (F12 > Console)**: deve aparecer `[KBWizard] init ok {kbId: ..., total: ...}`. Se aparecer erro vermelho, copie.
3. **Network (F12 > Network)**: filtre `kbwizard` - `kbwizard.css` e `kbwizard.js` devem retornar 200, n�o 404. Se 404, o plugin est� na pasta errada (`plugins` vs `marketplace`). Copie para as duas pastas.
4. **Verifique tabela**: no MySQL: `SHOW TABLES LIKE 'glpi_plugin_kbwizard%';` deve retornar 3 tabelas. Se 0, a instala��o falhou - desinstale e reinstale.
5. **Modo sem wizard**: se artigo sem `is_active`, o JS n�o deve fazer nada. Se travar at� sem wizard, desative temporariamente o plugin para confirmar que � ele.

## Teste isolado (sem GLPI)
Abra `E:\GPLI\Plugin\kbwizard\demo.html` direto no navegador. Deve abrir o modal com 5 passos. Se este demo tamb�m travar, o problema � no navegador (bloqueador). Se demo funciona mas GLPI n�o, � o hook PHP.

## Changelog 1.0.1
- `setup.php` defensivo + `__DIR__`
- `hook.php` migration defensiva
- `inc/config.class.php` + `inc/step.class.php` guard tableExists
- `js/kbwizard.js` sem MutationObserver, init guard `_inited`, fallback localStorage
- `ajax/progress.php` JSON sempre, suporte plugins/marketplace
