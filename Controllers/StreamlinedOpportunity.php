<?php

namespace StreamlinedOpportunity\Controllers;

use MapasCulturais\i;
use MapasCulturais\App;
use MapasCulturais\Controller;
use MapasCulturais\Entities\Registration;
use StreamlinedOpportunity\Plugin;
use MapasCulturais\Entities\Opportunity;

/**
 * StreamlinedOpportunity Controller
 *
 * @property-read \MapasCulturais\Entities\Registration $requestedEntity The Requested Entity
 * @property-read mixed $config configuração do plugin
 */
class StreamlinedOpportunity extends \MapasCulturais\Controllers\Registration
{  

    /**
     * Instância do plugin
     *
     * @var \StreamlinedOpportunity\Plugin
     */
    protected $plugin;

    protected $_initiated = false;

    function __construct()
    {
        parent::__construct();
        $this->entityClassName = Registration::class;
    }

    /**
     * Retorna uma instância do controller
     * @param string $controller_id 
     * @return StreamlinedOpportunity 
     */
    static public function i(string $controller_id): Controller {
        $instance = parent::i($controller_id);
        $instance->init($controller_id);

        return $instance;
    }

    protected function init($controller_id) {
        if(!$this->_initiated) {
            $app = App::i();
            $this->plugin = Plugin::getInstanceBySlug($controller_id);
            $this->layout = 'streamlined-opportunity';

            $slug = $this->plugin->getSlug();

            $app->hook("<<GET|POST|PUT|PATCH|DELETE>>({$slug}.<<*>>):before", function () {
                $registration = $this->getRequestedEntity();

                if (!$registration || !$registration->id) {
                    return;
                }

                $opportunity = $registration->opportunity;

                $this->registerRegistrationMetadata($opportunity);
            });

            $this->_initiated = true;
        }

    }

    function getTemplatePrefix() {
        return 'streamlinedopportunity';
    }

    function getConfig() {
        return $this->plugin->config;
    }
    
    /**
     * Retorna o valor com prefixo refenreciando o slug
     *
     * @param  mixed $value
     * @return string
     */
    function prefix($value){
        return $this->plugin->prefix($value);
    }
 
    /**
     * Retorna a oportunidade
     *
     * @return \MapasCulturais\Entities\Opportunity;
     */
    function getOpportunity(): Opportunity
    {
        $app = App::i();

        $opportunity_id = $this->config['opportunity_id'];
        $opportunity = $app->repo('Opportunity')->find($opportunity_id);

        if(!$opportunity){
            // @todo tratar esse erro
            throw new \Exception();
        }

        return $opportunity;
    }

    /**
     * Retorna o array associativo com os numeros e nomes de status
     *
     * @return array
     */
    function getStatusNames(){
        $summaryStatusName = [
            Registration::STATUS_DRAFT => i::__('Rascunho', 'streamlined-opportunity'),
            Registration::STATUS_SENT => i::__('Em análise', 'streamlined-opportunity'),
            Registration::STATUS_APPROVED => i::__('Aprovado', 'streamlined-opportunity'),
            Registration::STATUS_NOTAPPROVED => i::__('Reprovado', 'streamlined-opportunity'),
            Registration::STATUS_WAITLIST => i::__('Recursos Exauridos', 'streamlined-opportunity'),
            Registration::STATUS_INVALID => i::__('Inválida', 'streamlined-opportunity'),
        ];
        return $summaryStatusName;
    }

    /**
     * 
     * Endpoint para enviar emails das oportunidades
     * 
     * Exemplo: /{$slug}/sendEmails/opportunity:1/status:10
     * 
     */
    function ALL_sendEmailsPayments(){
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '768M');

        $this->requireAuthentication();
      
        $app = App::i();

        $opportunity = $this->getOpportunity();
        
        if (!$opportunity) {
            $this->errorJson('Oportunidade não encontrada');
        }

        if (empty($this->data['lote'])) {
            $this->errorJson('Informe o lote que deseja efetuar os disparos de e-mail Ex.: Lote 01');
        }

        $opportunity->checkPermission('@control');

        if (empty($this->data['status'])) {
            $status = '2,3,8,10';
        } else {
            $status = intval($this->data['status']);
            if (!in_array($status, [2, 3, 8, 10])) {
                $this->errorJson('Os status válidos são 2, 3, 8 ou 10');
                die;
            }
        }

        $registrations = $app->em->getConnection()->fetchAll("
            SELECT
                r.id,
                r.status,
                les.value AS last_email_status
            FROM registration r
                LEFT JOIN
                    registration_meta les ON
                        les.object_id = r.id AND
                        les.key = '{$this->prefix("last_email_status")}'
            WHERE
                r.opportunity_id = {$opportunity->id} AND
                r.status IN ({$status})               
            ORDER BY r.sent_timestamp ASC");
            
        $_SESSION['error'] = [];       
        foreach ($registrations as &$reg) {
            $reg = (object) $reg;
            $registration = $app->repo('Registration')->find($reg->id);
            $lots = json_decode($registration->genericpaymentexport_reference_export);

            if($lots && !in_array(strtolower($this->data['lote']), array_change_key_case($lots, CASE_LOWER))){
                $app->log->debug("INSCRIÇÃO {$registration->number} NÃO PERTENCE AO LOTE {$this->data['lote']}");
                continue;
            }

            $lot_sends = $registration->{$this->prefix("last_email_lot")};
            if($registration->{$this->prefix("last_email_lot")} && in_array(strtolower($this->data['lote']), $lot_sends)){
                $app->log->debug("EMAIL DE PAGAMENTO DO LOTE {$this->data['lote']} JÁ ENVIADO PARA {$registration->number}");
                continue;
            }

            $payment = false;
            $paymentMeta = $registration->metadata['financial_validator_raw'] ?? false;
          
            if ($paymentMeta) {
                $payments = json_decode($paymentMeta);
                foreach($payments as $payment){
                    if(in_array($payment->VALIDACAO, ['aprovado', 'aprovada', 'selecionado', 'selecionada'])){
                        $payment = true;
                    }                    
                }
                
            }           
            
            if(!$payment){
                $app->log->debug("NÃO EXISTE PAGAMENTO ASSOCIADO PARA {$registration->number}");
                continue;
            }

            $this->sendEmail($registration, $payment);
            
        }

        //Exibe na tela se exitiu algum erro de envio
        if($_SESSION['error']){
            var_dump($_SESSION['error']);
        }else{
            echo i::__('Todos os e-mails foram enviados', 'streamlined-opportunity');
        }

    }

    /**
     * Envia email com status da inscrição
     */
    function sendEmail(Registration $registration, $payment = false){
        $app = App::i();

        $mustache = new \Mustache_Engine();
        $site_name = $app->view->dict('site: name', false);
        $baseUrl = $app->getBaseUrl();

        $filename = $app->view->resolveFilename("views/streamlinedopportunity", "email-payments.html");       
        $template = file_get_contents($filename);
        $email_payment = $this->config['email_payment'][$registration->status];

        $messageBody = '';
        
        $params = [
            "siteName" => $site_name,
            "urlImageToUseInEmails" => $this->config['logo_center'],
            "user" => $registration->owner->name,
            "inscricaoId" => $registration->id, 
            "inscricao" => $registration->number, 
            "statusNum" => $registration->status,
            "statusTitle" => $email_payment['title'],
            "messageBody" => $messageBody,
            "baseUrl" => $baseUrl,
            "slug"=> $this->config['slug']
        ];
      
        $content = $mustache->render($template,$params);
        
        if (!empty($content)) {

            $email_params = [
                'from' => $app->config['mailer.from'],
                'to' => $registration->owner->user->email,
                'subject' => $site_name . i::__("Informações sobre o pagamento", 'streamlined-opportunity'),
                'body' => $content,
                'bcc' => $this->config['email_hidden_copy']
            ];

            
            if($app->createAndSendMailMessage($email_params)){

                //Registra o disparo do e-mail
                $app->log->debug("ENVIANDO EMAIL DE PAGAMENTO {$registration->number} ({$email_payment['title']})");

                $sent_emails = $registration->{$this->prefix("sent_emails")};
                $last_email_lot = $registration->{$this->prefix("last_email_lot")}; 
                $last_email_lot[] = $this->data['lote'];

                $sent_emails[] = [
                    'type' => "payment",
                    'timestamp' => date('Y-m-d H:i:s'),
                    'loggedin_user' => [
                        'id' => $app->user->id,
                        'email' => $app->user->email,
                        'name' => $app->user->profile->name
                    ],
                    'email' => $email_params,
                    'registration_set_status' => $registration->status
                ];

                $app->disableAccessControl();
                $registration->{$this->prefix("sent_emails")} = $sent_emails;

                $registration->{$this->prefix("last_email_lot")} = $last_email_lot;

                $registration->{$this->prefix("last_email_status")} = $registration->status;

                $registration->save(true);
                $app->enableAccessControl();
            }else{
                $_SESSION['error'][] = [
                    $registration->number,
                    i::__("Não foi enviado e-mail do lote {$this->data['lote']}", 'streamlined-opportunity')
                ];

                $app->log->debug("NÃO ENVIANDO EMAIL DE PAGAMENTO {$registration->number} ({$email_payment['title']})");
            }            

        }

    }
    /**
     * Retorna Array com informações sobre o status de uma inscrição
     *
     * @return array
     */
    function getRegistrationStatusInfo(Registration $registration){
        $app = App::i();
        // retorna a mensagem de acordo com o status
        $getStatusMessages = $this->getStatusMessages();
        $registrationStatusInfo=[];
        $registrationStatusInfo['registrationStatusMessage'] = $getStatusMessages[$registration->status];
        // retorna as avaliações da inscrição
        $evaluations = $app->repo('RegistrationEvaluation')->findByRegistrationAndUsersAndStatus($registration);
        
        // monta array de mensagens
        $justificativaAvaliacao = [];

        if (in_array($registration->status, $this->config['display_default_result'])) {
            $justificativaAvaliacao[] = $getStatusMessages[$registration->status];
        }
        
        foreach ($evaluations as $evaluation) {

            if ($evaluation->getResult() == $registration->status) {
                
                if (in_array($evaluation->user->id, $this->config['evaluators_user_id']) && in_array($registration->status, $this->config['exibir_resultado_dataprev'])) {
                    // resultados do dataprev
                    $justificativaAvaliacao[] = $evaluation->getEvaluationData()->obs ?? '';
                } elseif (in_array($evaluation->user->id, $this->config['evaluators_generic_user_id']) && in_array($registration->status, $this->config['exibir_resultado_generico'])) {
                    // resultados dos avaliadores genericos
                    $justificativaAvaliacao[] = $evaluation->getEvaluationData()->obs ?? '';
                } 
                
                if (in_array($registration->status, $this->config['display_result_evaluators']) && !in_array($evaluation->user->id, $this->config['evaluators_user_id']) && !in_array($evaluation->user->id, $this->config['evaluators_generic_user_id'])) {
                    // resultados dos demais avaliadores
                    $justificativaAvaliacao[] = $evaluation->getEvaluationData()->obs ?? '';
                }

            }
            
        }
        $registrationStatusInfo['justificativaAvaliacao'] = $justificativaAvaliacao;
        return $registrationStatusInfo;
    }
    /**
     * Retorna array associativo com mensagens para cada status da inscrição
     *
     * @return array
     */
    function getStatusMessages(){
        $summaryStatusMessages = [
            //STATUS_SENT = 1 - Em análise
            '1' => [
                'title'   => $this->config['title_status_sent'],
                'message'  => $this->config['msg_status_sent']
            ],
            //STATUS_INVALID = 2 - Inválida
            '2' => [
                'title'    => $this->config['title_status_invalid'],
                'message'  => $this->config['msg_status_invalid']
            ],
            //STATUS_NOTAPPROVED = 3 - Reprovado
            '3' => [
                'title'    => $this->config['title_status_notapproved'],
                'message'  => $this->config['msg_status_notapproved']
            ],
            //STATUS_APPROVED = 10 - Aprovado
            '10' => [
                'title'   => $this->config['title_status_approved'],
                'message' => $this->config['msg_status_approved']
            ],
            //STATUS_WAITLIST = 8 - Recursos Exauridos
            '8' => [
                'title'   => $this->config['title_status_waitlist'],
                'message' => $this->config['msg_status_waitlist']
            ]
        ];
        return $summaryStatusMessages;
    }

    function finish($data, $status = 200, $isAjax = false)
    {
        if (is_array($data)) {
            $data['redirect'] = 'false';
        } else if (is_object($data)) {
            $data->redirect = 'false';
        }
        parent::finish($data, $status, $isAjax);
    }

    /**
     * Redireciona o usuário para o formulário
     * 
     * rota: /{$slug}/registration/[?agent={agent_id}]
     * 
     * @return void
     */
    function GET_registration()
    {
        $this->requireAuthentication();

        $app = App::i();

        $app->view->includeEditableEntityAssets();

        if ($app->user->is('mediador')) {
            $agent = $this->createMediado();

            $app->redirect($this->createUrl('novaInscricao', ['agent' => $agent->id]));
            
        } else if (isset($this->data['agent']) && $this->data['agent'] != "" ) {
            $agent = $app->repo('Agent')->find($this->data['agent']);
        } else {
            $agent = $app->user->profile;
        }

        $metadata_key = $this->prefix("registration");

        // se ainda não tem inscrição
        if (!isset($agent->$metadata_key)) {
            /** 
             * verificar se o usuário tem mais de um agente, 
             * se tiver redireciona para a página de escolha de agente
             */
            $agent_controller = $app->controller('agent');

            $num_agents = $agent_controller->apiQuery([
                '@select' => 'id',
                '@permissions' => '@control',
                'type'=>'EQ(1)',
                '@count' => 1
            ]);                    
            if ($num_agents > 1) {
                // redireciona para a página de escolha de agente
                $app->redirect($this->createUrl('selecionar_agente',['tipo' => 1]));
            } else {

                // redireciona para a rota de criação de nova inscrição
                $app->redirect($this->createUrl('novaInscricao', ['agent' => $app->user->profile->id]));
            }
        }

        $app->redirect($this->createUrl('formulario', [$agent->$metadata_key]));
    }

    /**
     * Cria nova inscrição para o agente informado e redireciona para o formulário
     * 
     */
    function GET_novaInscricao()
    {   
        $this->requireAuthentication();
        if (!isset($this->data['agent'])) {
            // @todo tratar esse erro
            throw new \Exception(i::__('O parâmetro `agent` é obrigatório', 'streamlined-opportunity'));
        }

        $app = App::i();
        $agent = $app->repo('Agent')->find($this->data['agent']);
        //verifica se existe e se o agente owner é individual
          //se é coletivo cria um agente individual
        if ($agent->type->id == 2){
            $app->disableAccessControl();
            $agent = new \MapasCulturais\Entities\Agent();
            //@TODO: confirmar nome e tipo do Agente coletivo
            $agent->name = ' ';
            $agent->type = 1;
            $agent->save(true);
            $app->enableAccessControl();
        }
        if(!$agent || $agent->type->id != 1){
            // @todo tratar esse erro
            throw new \Exception(i::__('O tipo do agente deve ser individual', 'streamlined-opportunity'));
        }
        $agent->checkPermission('@control');

        $opportunity = $this->getOpportunity();

        $registrations = $app->repo('Registration')->findBy(['owner' => $agent->id, 'opportunity' => $opportunity->id]);

        if(count($registrations) >=  $this->plugin->config['limit']){
            $registration_id = $registrations[0]->id;
        }else{
            $registration = new \MapasCulturais\Entities\Registration;
            $registration->owner = $agent;
            $registration->opportunity = $this->getOpportunity();    
            $registration->save(true);
            $registration_id =  $registration->id;
        }

        $app->redirect($this->createUrl('formulario', [$registration_id]));
    }


    /**
     * Tela onde o usuário acompanha o status da inscrição
     *
     * @return void
     */
    function GET_status()
    {
        $app = App::i();

        $this->requireAuthentication();
        $registration = $this->requestedEntity;

        $defaultText = false;
        $evaluateDefault = "";
        if(in_array($registration->id, $this->config['email_alter_status']['noSendEmail'])){
            $defaultText = true;
            if(in_array($registration->id, array_keys($this->config['email_alter_status']['specialMessage']))){
                $evaluateDefault = $this->config['email_alter_status']['specialMessage'][$registration->id];
            }else{
                $evaluateDefault = $this->config['email_alter_status']['messageDefaultNoSendEmail'];
            }
        }


        if(!$registration) {
            $app->pass();
        }
        if($registration->status == 0) {
            $app->redirect($this->createUrl('cadastro'));
        }
        $registration->checkPermission('view');

        // retorna a mensagem de acordo com o status
        $getStatusMessages = $this->getStatusMessages();
        $registrationStatusMessage = $getStatusMessages[$registration->status];
        
        // monta array de mensagens
        $justificativaAvaliacao = [];

        $recursos = [];

        // retorna informações de pagamento       
        $paymentMeta = $registration->metadata['secult_financeiro_raw'] ?? false;
            
        $payment = false;
        if ($paymentMeta && strpos($paymentMeta, 'Caso tenha algum problema com seu pagamento, entre em contato com o suporte') && strpos($paymentMeta, '"AVALIACAO":"selecionada"')) {
            $payment = true;
        }

        if($payment){
            // Verifica se é uma inscrição desbancarizada
            $accountCreationSecult = $registration->owner->metadata['account_creation'] ?? false;
            $branch = $registration->owner->payment_bank_branch ?? false;
            $secultRaw = json_decode($registration->metadata['secult_financeiro_raw'], true);
            if ($accountCreationSecult && $branch) {

                // Mensagem de Status para desbancarizados que possuem a conta criada pela SECULT.
                $messageStatus = 'O pagamento foi realizado. Para ter acesso ao auxílio, dirija-se até a agência ';
                $messageStatus .= $branch;
                $messageStatus .= ' para validar a abertura de sua conta pela SECULT. Lembre-se de levar RG, CPF e comprovante de residência.';
                $messageStatus .= '<br><br>';
                $messageStatus .= $secultRaw['OBSERVACOES'];
                $justificativaAvaliacao[] = $messageStatus;

            }else{

                $messageStatus = 'O pagamento do seu benefício foi realizado e já está disponível para saque na conta indicada no momento de sua inscrição.';
                $messageStatus .= '<br><br>';
                $messageStatus .= $secultRaw['OBSERVACOES'];            
                $justificativaAvaliacao[] = $messageStatus;
                
            }
            $registrationStatusMessage['title'] = 'Seu pagamento foi realizado com sucesso!!!';
        }else{

            // retorna as avaliações da inscrição
            $evaluations = $app->repo('RegistrationEvaluation')->findByRegistrationAndUsersAndStatus($registration);
                        
            if (in_array($registration->status, $this->config['display_default_result'])) {

                $justificativaAvaliacao[] = $getStatusMessages[$registration->status];

                foreach ($evaluations as $evaluation) {
                    $validacao = $evaluation->user->metadata['validator_for'] ?? null;

                    if ($validacao == 'recurso') {
                        $recursos[] = $evaluation;
                    }
    
                    // Verifica a configuração `not_display_results`
                    if (!in_array($evaluation->user->id, $this->config['not_display_results'])) {
                    
                        if (in_array($evaluation->user->id, $this->config['evaluators_user_id']) && in_array($registration->status, $this->config['exibir_resultado_dataprev'])) {
                            // resultados do dataprev
                            $avaliacao = $evaluation->getEvaluationData()->obs ?? '';
                            if (!empty($avaliacao)) {
                                if (($registration->status == 3 || $registration->status == 2) && substr_count($evaluation->getEvaluationData()->obs, 'Reprocessado')) {
    
                                    if ($this->config['msg_reprocessamento_dataprev']) {
                                        $justificativaAvaliacao[] = $this->config['msg_reprocessamento_dataprev'];
                                    } else {
                                        $justificativaAvaliacao[] = $avaliacao;
                                    }
                                    
                                } else {
                                    $justificativaAvaliacao[] = $avaliacao;
                                }
                            }
                        } elseif (in_array($evaluation->user->id, $this->config['evaluators_generic_user_id']) && in_array($registration->status, $this->config['exibir_resultado_generico'])) {
                            // resultados dos avaliadores genericos
                            $justificativaAvaliacao[] = $evaluation->getEvaluationData()->obs ?? '';
                        }

                        if (in_array($registration->status, $this->config['display_result_evaluators']) && !in_array($evaluation->user->id, $this->config['evaluators_user_id']) && !in_array($evaluation->user->id, $this->config['evaluators_generic_user_id'])) {
                            if (!in_array($evaluation, $recursos)) {
                                // resultados dos demais avaliadores
                                $justificativaAvaliacao[] = $evaluation->getEvaluationData()->obs ?? '';
                            }
                        }
                        
                    }


                }
    
            }

        }

        $avaliacoesRecusadas = $this->processaDeParaAvaliacoesRecusadas($registration);

        $this->render('status', [
            'registration' => $registration, 
            'registrationStatusMessage' => $registrationStatusMessage, 
            'justificativaAvaliacao' => array_filter($justificativaAvaliacao),
            'recursos' => $recursos,
            'avaliacoesRecusadas' => $avaliacoesRecusadas,
            'defaultText' => $defaultText,
            'evaluateDefault' => $evaluateDefault
        ]);
    }     
    
    /**
     * Seta um metadado indicando que se iniciou o streamLined da opportunidade
     */
    public function GET_startstreamlined ()
    {
        $this->requireAuthentication();

        $app = App::i();

        if(!$app->user->is('admin')) {
            $this->errorJson('Permissao negada', 403);
        }
        
        $request = $this->data;

        $opportunity = $this->getOpportunity();

        $opportunity->{$this->prefix("streamlined_start")} = ($request['start'] == "true") ? true : false;
        
        $opportunity->save();

    }


    /**
     * Renderiza o formulário da solicitação
     * 
     * rota: /{$slug}}/formulario/[{registration_id}]
     * 
     * @return void
     */
    function GET_formulario()
    {
        $app = App::i();
        $this->requireAuthentication();

        $registration = $this->getRequestedEntity();
        if($registration->status != Registration::STATUS_DRAFT){
            $app->redirect($this->createUrl('status', [$registration->id]));
        }
        $registration->checkPermission('modify');
        $now = new \DateTime('now');
        $notInTime = ($registration->opportunity->registrationFrom > $now || $registration->opportunity->registrationTo < $now );
        if ($notInTime){
            $app->redirect($this->createUrl('cadastro'));
        }
        if (!$registration->{$this->prefix("has_accepted_terms")}) {
            $app->redirect($this->createUrl('termos_e_condicoes', [$registration->id]));
        }
        
        // já é registrado no init do controller
        // $this->registerRegistrationMetadata($registration->opportunity);
        
        $app->view->includeEditableEntityAssets();

        $this->render('registration-edit', ['entity' => $registration]);
    }

    /**
     * Encaminha o usuário para a rota correta, de acordo com o tipo do usuário
     *
     * @return void
     */
    function GET_index()
    {
        $this->requireAuthentication();

        $app = App::i();

        $app->redirect($this->createUrl('cadastro'));

    }

    /**
     * Tela inicial para o proponente
     *
     * @return void
     */
    function GET_cadastro()
    {
        $this->requireAuthentication();
        
        $app = App::i();

        $controller = $app->controller('registration');

        $summaryStatusName = $this->getStatusNames();

        $owner_name = $app->user->profile->name;

        $repo = $app->repo('Registration');
        
        $opportunity = $this->getOpportunity();
       
        $registrations = $controller->apiQuery([
            '@select' => 'id', 
            'opportunity' => "EQ({$opportunity->id})", 
            'status' => 'GTE(0)'
        ]);
        
        $registrations_ids = array_map(function($r) { return $r['id']; }, $registrations);
        $registrations = $repo->findBy(['id' => $registrations_ids ]);

        $this->render('cadastro', [
                'limit' => $this->config['limit'],
                'registrations' => $registrations,
                'summaryStatusName'=> $summaryStatusName, 
                'niceName' => $owner_name,
                'isRegistrationOpen' => ($this->plugin->isStartStreamLined() && $registrations_ids) ? true : false,
        ]);
    }

    /**
     * Página de aceite dos termos e condições
     * 
     * rota: /{$slug}/aceitar_termos/{id_inscricao}
     * 
     * @return void
     */
    function GET_termos_e_condicoes()
    {
        $this->requireAuthentication();
        
        
        if (!isset($this->data['id']) || $this->data['id'] == "" ) {
            // @todo tratar esse erro
            throw new \Exception();
        }

        $app = App::i();
        
        $registration = $app->repo('Registration')->find($this->data['id']);

        if (!$registration->id) {
            $app->pass();
        }
        
        $this->render('termos-e-condicoes', ['registration_id' => $registration->id]);
    }

    /**
     * Aceitar os termos e condiçoes
     * 
     * rota: /{$slug}}/aceitar_termos/{id_inscricao}
     * 
     * @return void
     */
    function GET_aceitar_termos()
    {
        $this->requireAuthentication();
        $registration = $this->requestedEntity;
        $registration->checkPermission('modify');
        $registration->{$this->prefix("has_accepted_terms")} = true;
        $registration->save(true);
        $app = App::i();
        $app->redirect($this->createUrl('formulario', [$registration->id]));
    }

    function GET_selecionar_agente()
    {
        $this->requireAuthentication();
        $app = App::i();
        $tipo = 1;
        $agent_controller = $app->controller('agent');
        $agentsQuery = $agent_controller->apiQuery([
            '@select' => 'id,name,type,terms',
            '@permissions' => '@control',
            '@files' => '(avatar.avatarMedium):url',
            'type'=>'EQ(' . $tipo . ')',
        ]);
        $agents= [];
        foreach($agentsQuery as $agent){
            $agentItem         = new \stdClass();
            $agentItem->id     = $agent['id'];
            $agentItem->name   = $agent['name'];
            $agentItem->avatar = isset($agent['@files:avatar.avatarMedium']) ? $agent['@files:avatar.avatarMedium']['url']: '';
            $agentItem->type   = $agent['type']->name;
            $agentItem->areas  = $agent['terms']['area'];
            array_push($agents, $agentItem);
        }
        //Ordena o array de agents pelo name
        usort($agents, function($a, $b) {return strcmp($a->name, $b->name);});
        $this->data['agents'] = $agents;
        $this->render('selecionar-agente', $this->data);
    }

    /**
     * Confirmação de dados antes do envio do formulário
     * 
     * rota: /{$slug}/confirmacao/{id_inscricao}
     * 
     * @return void
     */
    function GET_confirmacao()
    {
        $app = App::i();
        $this->requireAuthentication();
        //verificar se registration status
        $registration = $this->getRequestedEntity();
        if($registration->status != Registration::STATUS_DRAFT){
            $app->redirect($this->createUrl('status', [$registration->id]));
        }
        if (!$registration->{$this->prefix("has_accepted_terms")}) {
            $app->redirect($this->createUrl('termos_e_condicoes', [$registration->id]));
        }
        $registration->checkPermission('control');
        $this->data['entity'] = $registration;
        $this->render('registration-confirmacao', $this->data);
    }
    
    function GET_email_recusadas() {
        
        $this->requireAuthentication();

        $app = App::i();

        if(!$app->user->is('admin')) {
            $this->errorJson('Permissao negada', 403);
        }
        
        ini_set('max_execution_time', 0);
        ini_set('memory_limit', '-1');
        
        $opportunity_id = $this->config['opportunity_id'];

        $dql = "
            SELECT 
                e.id 
            FROM 
                MapasCulturais\\Entities\\Registration e
            WHERE 
                e.opportunity = $opportunity_id AND
                e.status IN (2,3)";

        $registrations = $app->em->createQuery($dql)->getArrayResult();

        $dias = $this->config['dias_para_recurso'];
        $dataLimite = new \DateTime('now');
        $dataLimite->modify('+' . $dias . ' day');

        $total = count($registrations);
        $count = 0;
        foreach ($registrations as $reg) {
            $count++;
            $r = $app->repo('Registration')->find($reg['id']);
            if ($r->{$this->prefix("appeal_deadline")}) {
                $app->log->debug("{$count}/{$total} -- EMAIL RECUSADAS, INSCRIÇÃO {$r->number} JÁ ENVIADA");
            } else {
                $app->log->debug("{$count}/{$total} -- EMAIL RECUSADAS, INSCRIÇÃO {$r->number} AINDA NÃO ENVIADA");
                $emailenviado = $this->enviaEmailRecusadas($r, $dataLimite);
            }
            $app->em->clear();
        }
    }

    function enviaEmailRecusadas($registration, $dataLimite){
        $app = App::i();
        $mustache = new \Mustache_Engine(); //pega um template e add variaveis (sendo usado linha 22)
        $site_name = $app->view->dict('site: name', false);
        $baseUrl = $app->getBaseUrl();
        $filename = $app->view->resolveFilename("views/streamlined-opportunity", "email-recusadas.html");
        $template = file_get_contents($filename); 
        $avaliacoes = $this->processaDeParaAvaliacoesRecusadas($registration);
            
        $params = [
            "siteName" => $site_name,
            "urlImageToUseInEmails" => $this->config['logo_center'],
            "user" => $registration->owner->name,
            "inscricao" => $registration->number,            
            "baseUrl" => $baseUrl,            
            "dataLimite" => $dataLimite->format('d/m/Y'),
            "avaliacoes" => $avaliacoes
        ];         
        
        $content = $mustache->render($template,$params); 
        $email_params = [
            'from' => $app->config['mailer.from'],
            'to' => $registration->agentsData['owner']["emailPrivado"],
            'subject' => $site_name . " - Dados Para Recurso",
            'body' => $content
        ];

        $emailSent = '';
        
        // Envia e-mail apenas para inscrições que possuem avaliações tratadas pela config `de_para_avaliacoes`
        if (!empty($avaliacoes)){
            $app->log->debug("ENVIANDO EMAIL RECUSADAS, INSCRIÇÃO {$registration->number} ...");
            $emailSent = $app->createAndSendMailMessage($email_params);
        } else {
            $app->log->debug("NÃO FORAM ENCONTRADAS AVALIAÇÕES COM DE->PARA da {$registration->number}");
        }

        if ($emailSent){

            $app->log->debug("E-MAIL ENVIADO COM SUCESSO!");
            $app->log->debug("==================================================================");

            $sent_emails = $registration->{$this->prefix("sent_emails")};
            $sent_emails[] = [
                'timestamp' => date('Y-m-d H:i:s'),
                'loggedin_user' => [
                    'id' => $app->user->id,
                    'email' => $app->user->email,
                    'name' => $app->user->profile->name 
                ],
                'email' => 'email - recusadas'
            ];

            $sent_emails = $this->prefix("sent_emails");
            $limite_recurso = $this->prefix("limite_recurso");
            $app->disableAccessControl();
            $registration->{$this->prefix("sent_emails")} = $sent_emails;
            $registration->{$this->prefix("appeal_deadline")} = $dataLimite->format('Y-m-d 00:00');
            $registration->save(true);
            $app->enableAccessControl();

        } else {

            $app->log->debug("ERRO AO TENTAR ENVIAR E-MAIL DA INSCRIÇÃO {$registration->number}");
            $app->log->debug("==================================================================");

        }
    }

    /**
     * 
     * Função para retornar todas as avalições das inscrições recusadas
     * passando pela config `de_para_avaliacoes`
     * 
     */
    function processaDeParaAvaliacoes ($registration){
        $app = App::i();
        $avaliacoes = $app->repo('RegistrationEvaluation')->findByRegistrationAndUsersAndStatus($registration);
        $configDePara = $this->config['de_para_avaliacoes'] ?? '';
        if(!empty($configDePara)){ 
            foreach($avaliacoes as $a){
                if ($a->result == 2 || $a->result == 3){
                    $novaAvaliacao = '';
                    $evaluationData = $a->getEvaluationData();
                    $obs = $evaluationData->obs;                    
                    foreach($configDePara as $key => $value) {
                        $pos = strpos($obs, $key);                        
                        if ($pos !== false) {
                            $novaAvaliacao .= $value . '. ';
                        }   
                    }          
                    if (!empty($novaAvaliacao)) {
                        $evaluationData->obs = $novaAvaliacao;
                        $a->setEvaluationData($evaluationData);
                    }  
                }                             
            }           
        }
        return $avaliacoes;
    }

    /**
     * 
     * Função para retornar apenas as avalições das inscrições recusadas
     * e que foram alteradas pela config `de_para_avaliacoes`
     * 
     */
    function processaDeParaAvaliacoesRecusadas($registration) {
        
        $app = App::i();
        $avaliacoes = $app->repo('RegistrationEvaluation')->findByRegistrationAndUsersAndStatus($registration);
        $configDePara = $this->config['de_para_avaliacoes'] ?? '';
        $avaliacoesProcessadas = [];
                         
        if(!empty($configDePara)){ 
            foreach($avaliacoes as $a){
                if ($a->result == 2 || $a->result == 3){                    
                    $evaluationData = $a->getEvaluationData();
                    $obs = $evaluationData->obs;           
                    foreach($configDePara as $key => $value) {
                        $pos = strpos($obs, $key);                        
                        if ($pos !== false && !in_array($value, $avaliacoesProcessadas)) {                            
                            $avaliacoesProcessadas[] = $value;
                        }
                    }
                }
            }
        }
        return $avaliacoesProcessadas;
    }
}
