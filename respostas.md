# Plano de Implementacao das Questoes

## 1. Package Optimization
1. Executar `npm run build` e inspecionar `dist` e o zip gerado, anotando pastas grandes (especialmente `vendor` ou `node_modules`).
2. Mapear dependencias que podem ser tratadas como externas, configurando webpack/wp-scripts para usar os bundles nativos do WordPress (React, lodash, etc.).
3. Ajustar o script de build para rodar `npm prune --production` e excluir dependencias de desenvolvimento do pacote final.
4. Configurar o empacotamento para copiar apenas arquivos essenciais (`includes`, `build`, `languages`, `readme`) criando `.distignore` ou lista controlada no zipper.
5. Validar o plugin instalado a partir do zip otimizado e registrar a reducao de tamanho antes/depois.

## 2. Google Drive Admin Interface (React)

### 2.1 Internacionalizacao e estado
1. Revisar componentes do admin e envolver textos em `__`, `_x` ou `sprintf`, carregando `load_plugin_textdomain` no bootstrap.
2. Centralizar estado de autenticacao e credenciais em hook/contexto dedicado, expondo flags como `hasCredentials` e `isAuthenticated`.
3. Consumir endpoint que retorna credenciais salvas via `useEffect`, exibindo placeholders enquanto aguarda resposta.
4. Condicionar renderizacao das secoes conforme o estado atual, garantindo que telas sem credenciais, aguardando autenticacao ou autenticadas exibam instrucoes corretas.
5. Validar traducoes com `setLocaleData` em ambiente de teste e ajustar textos que faltarem.

### 2.2 Gestao de credenciais
1. Criar formulario controlado com campos `clientId` e `clientSecret` usando componentes `TextControl` do pacote `@wordpress/components`.
2. Exibir `redirectUri` dinamica calculada no backend e enviada para o front, permitindo copiar facilmente.
3. Renderizar lista dos escopos obrigatorios em bloco dedicado, com textos traduziveis e link para documentacao.
4. No envio, validar campos obrigatorios, opcionalmente criptografar dados antes de enviar e chamar POST `wp-json/wpmudev/v1/drive/save-credentials`.
5. Atualizar estado local com resposta do servidor e mostrar notificacao de sucesso ou erro via componente `Notice`.

### 2.3 Fluxo de autenticacao
1. Criar acao que solicita ao backend uma URL de autorizacao e redireciona usuario ao Google.
2. Implementar manipulador de callback (via pagina admin ou REST) que troca o `code` retornado por tokens, atualizando estado global.
3. Exibir mensagens claras para sucesso, erro e cancelamento utilizando componentes de alerta do WordPress.
4. Persistir tokens no backend e acionar refetch do estado de autenticacao no front apos o retorno.
5. Registrar logs de erro (com `console.error` apenas em modo dev) e instrucoes de recuperacao para o usuario.

### 2.4 Interface de arquivos
1. Implementar formulario de upload com `<input type="file">`, validando tipo e tamanho antes de enviar para o endpoint REST de upload.
2. Exibir barra ou label de progresso usando `XMLHttpRequest` ou biblioteca com suporte a eventos `onUploadProgress`, desabilitando botoes durante a transferencia.
3. Construir formulario para criar pasta com `TextControl`, desabilitando acao enquanto o campo estiver vazio e chamando endpoint especifico.
4. Criar hook `useDriveFiles` que busca lista paginada de arquivos, atualiza automaticamente apos upload/criacao e retorna informacoes normalizadas.
5. Renderizar grid/tabela com nome, tipo, tamanho, data de modificacao, botao de download para arquivos e link "Ver no Drive" para todos os itens, incluindo estados de carregando, vazio e erro.

## 3. Backend: Endpoint de credenciais
1. Registrar rota REST `wpmudev/v1/drive/save-credentials` com permissao `manage_options` e `check_ajax_referer` para validar nonce.
2. Sanitizar `clientId` e `clientSecret` com `sanitize_text_field` e validar que ambos foram informados.
3. Se houver criptografia, carregar chave do `wp-config.php`, aplicar `openssl_encrypt` com `AES-256-CBC` e armazenar IV junto ao valor.
4. Persistir dados com `update_option( 'wpmudev_drive_credentials', $dados, false )`, definindo `autoload` como falso.
5. Retornar `wp_send_json_success` ou `wp_send_json_error` com mensagens traduziveis e HTTP status coerentes.

## 4. Backend: Autenticacao Google Drive
1. Criar classe que monta URL de autorizacao contendo cliente, escopos, redirect registrado e `state` salvo em transient temporario.
2. Registrar endpoint/callback que valida `state`, troca `code` por tokens via SDK Google e captura refresh token quando disponibilizado.
3. Armazenar tokens (access, refresh, expiracao) em opcao segura, aplicando criptografia se configurada.
4. Implementar rotina que verifica expiracao antes de cada chamada e executa refresh automaticamente quando necessario.
5. Tratar excecoes retornando `WP_Error` detalhado, registrando logs em `error_log` apenas quando `WP_DEBUG` estiver ativo.

## 5. Backend: Lista de arquivos
1. Registrar endpoint REST protegido que aceita parametros `pageToken`, `pageSize` e `query` para filtrar resultados.
2. Inicializar `Google_Service_Drive` com cliente autenticado usando tokens salvos.
3. Chamar `files->listFiles` com campos otimizados (`id,name,mimeType,size,modifiedTime,webViewLink`) e token de pagina quando fornecido.
4. Converter resposta em array amigavel ao front, incluindo informacoes de navegacao (proximo token) e mensagens de estado.
5. Retornar erros da API com codigos HTTP apropriados e mensagens traduziveis para exibir na interface.

## 6. Backend: Upload de arquivos
1. Criar endpoint `wpmudev/v1/drive/upload` aceitando multipart, validando capacidade `upload_files` e nonce/permisoes.
2. Validar tamanho maximo e tipos permitidos antes de enviar ao Google.
3. Usar `Google_Service_Drive_DriveFile` e `Google_Http_MediaFileUpload` (ou upload simples para arquivos pequenos) para enviar dados ao Drive.
4. Remover arquivos temporarios com `wp_delete_file` e registrar erros usando `WP_Error` quando houver falhas.
5. Responder com JSON contendo id do arquivo, nome e status para que o front atualize a lista.

## 7. Posts Maintenance Admin Page
1. Adicionar menu `Posts Maintenance` via `add_menu_page`, renderizando tela com filtros de post type e botao "Scan Posts".
2. Implementar acao que dispara REST/AJAX para enfileirar processo em background usando Action Scheduler ou WP Cron customizado.
3. Processar posts em lotes (p. ex. 50 por execucao), atualizando meta `wpmudev_test_last_scan` com `current_time( 'mysql', true )`.
4. Armazenar progresso (total, processados, ultimo lote) em transient/opcao e expor endpoint para o painel consultar periodicamente.
5. Agendar execucao diaria com `wp_schedule_event` reaproveitando a mesma rotina de processamento e exibir notificacao final ao usuario.

## 8. WP-CLI Integration
1. Registrar comando `wp wpmudev scan-posts` em `CLI\Posts_Scan_Command`, carregado apenas em contexto WP-CLI.
2. Aceitar argumento `--post_types=` que converte em array de tipos suportados, com validação de existencia.
3. Reutilizar servico do admin para executar escaneamento, exibindo barra de progresso com `WP_CLI\Utils\make_progress_bar`.
4. Logar etapas relevantes com `WP_CLI::log` e emitir resumo final via `WP_CLI::success` indicando quantos posts foram atualizados.
5. Documentar exemplos (`wp wpmudev scan-posts --post_types=post,page`) na funcao de registro e no README.

## 9. Dependency Management e compatibilidade
1. Composer agora obriga `classmap-authoritative` e `optimize-autoloader`, garantindo que o autoloader do plugin use apenas o mapa conhecido (evita que caminhos externos executem código inesperado).
2. O `vendor/autoload.php` é carregado mas re-registrado com `prepend = false`, o que garante que dependências de outros plugins continuem com prioridade — reduz a chance de conflitos entre versões do `google/apiclient`.
3. Documentei no README o fluxo recomendado (`composer install --no-dev --optimize-autoloader`) antes do `npm run build`, deixando claro como manter o zip final livre de dependências de desenvolvimento.
4. A seção de Composer também explica como reinstalar dependências e por que a estratégia atual evita sobrescrever pacotes globais.

## 10. Testes unitarios
1. Adicionei `bin/install-wp-tests.sh`, script padrão usado pelo `wp scaffold plugin-tests`, facilitando preparar o `wordpress-tests-lib` em qualquer ambiente.
2. Ampliei `tests/test-posts-maintenance-service.php` com cenários cobrindo filtros de post type, ausência de posts, limitação de batch size e bloqueio de execuções simultâneas (WP-CLI/Admin).
3. Há validações explícitas sobre atualização de metas, resumo final (`get_last_summary`) e mensagens de erro (`WP_Error`) quando não existem tipos válidos.
4. Criei o script Composer `composer test` descrito no README para rodar `vendor/bin/phpunit`, garantindo repetibilidade local e no CI.
