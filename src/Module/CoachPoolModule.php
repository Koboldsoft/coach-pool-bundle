<?php
namespace Koboldsoft\CoachPoolBundle\Module;

use Koboldsoft\CoachPoolBundle\Service\CoachPool;
use Contao\BackendTemplate;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\FrontendUser;
use Contao\Module;
use Contao\System;
use Symfony\Component\Security\Csrf\CsrfToken;

class CoachPoolModule extends Module
{
    protected $strTemplate = 'mod_coach_pool';

    public function generate(): string
    {
        if (TL_MODE === 'BE') {
            $template = new BackendTemplate('be_wildcard');
            $template->wildcard = '### COACH-POOL ###';
            $template->title = $this->name;
            return $template->parse();
        }
        return parent::generate();
    }

    protected function compile(): void
    {
        $GLOBALS['TL_CSS'][] = 'files/themes/webapp/css/coach-pool.css';
        $container = System::getContainer();
        $request = $container->get('request_stack')->getCurrentRequest();
        global $objPage;
        if ($objPage) { $objPage->cache = 0; }
        $request->attributes->set('_coach_pool_private', true);
        $reviewMode = $this->type === 'coach_pool_review';
        $this->Template->reviewMode = $reviewMode;
        $this->Template->entries = [];
        $this->Template->interests = [];
        $this->Template->error = '';
        $this->Template->message = '';
        $this->Template->authorized = false;
        $this->Template->formId = 'coach_pool_'.$this->id;
        if (!defined('FE_USER_LOGGED_IN') || !FE_USER_LOGGED_IN) { return; }
        $pool = $container->get(CoachPool::class);
        $memberId = (int) FrontendUser::getInstance()->id;
        $teamId = (int) $this->coachPoolTeam;
        $reviewers = \Contao\StringUtil::deserialize($this->coachPoolReviewers, true);
        $includeInterested = (bool) $this->coachPoolIncludeInterested;
        try {
            // Die Zugriffsprüfung erfolgt vor jedem Lesen von Vergabedaten und erneut beim Schreiben.
            if ($reviewMode ? !$pool->canReview($memberId, $teamId, $reviewers) : !$pool->allowed($memberId, $teamId)) { return; }
            $this->Template->authorized = true;
            $csrf = $container->get(ContaoCsrfTokenManager::class);
            $tokenName = $container->getParameter('contao.csrf_token_name');
            $this->Template->token = $csrf->getToken($tokenName)->getValue();
            if ($request->isMethod('POST') && $request->request->get('FORM_SUBMIT') === 'coach_pool_'.$this->id) {
                try {
                    if (!$csrf->isTokenValid(new CsrfToken($tokenName, (string) $request->request->get('REQUEST_TOKEN')))) {
                        throw new \DomainException('Die Sitzung ist abgelaufen. Bitte die Seite neu laden.');
                    }
                    if ($reviewMode) {
                        $pool->approve($memberId, $teamId, $reviewers, (string) $request->request->get('contact'), (string) $request->request->get('coach_email'));
                        $this->Template->message = 'Die Anfrage ist freigegeben. HubSpot übernimmt den weiteren Mailversand gemäß eingerichtetem Workflow.';
                    } else {
                        // Keine vom Browser übergebene Coach-E-Mail beim Melden von Interesse verwenden.
                        $pool->report($memberId, $teamId, (string) $request->request->get('contact'), $includeInterested);
                        $this->Template->message = 'Danke, wir prüfen kurz und melden uns innerhalb eines Werktags.';
                    }
                } catch (\DomainException $e) {
                    $this->Template->error = $e->getMessage();
                } catch (\Throwable $e) {
                    $pool->logLoadFailure($e);
                    $this->Template->error = 'Die Änderung konnte nicht bestätigt werden. Bitte den aktuellen Stand prüfen und bei Bedarf das Team kontaktieren.';
                }
            }
            $this->Template->interests = $pool->interests($memberId);
            $entries = $reviewMode ? $pool->reviewListing($memberId, $teamId, $reviewers) : $pool->listing('', $includeInterested);
            foreach ($entries as &$entry) {
                $entry['presentation'] = \Koboldsoft\CoachPoolBundle\Service\PoolPresentation::forContact($entry);
            }
            unset($entry);
            $this->Template->entries = $entries;
        } catch (\DomainException $e) {
            $this->Template->error = $e->getMessage();
        } catch (\Throwable $e) {
            $pool->logLoadFailure($e);
            $this->Template->error = 'Der Coach-Pool ist gerade nicht erreichbar. Bitte später erneut versuchen.';
        }
    }
}
