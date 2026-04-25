<?php
/**
 * Plugin Name: AlertOnFeed
 * Description: Get notifications for selected RSS feeds and send alerts via Pushover.
 * Author: Vntgcode
 * Version: 0.1.0
 */

class AlertOnFeedExtension extends Minz_Extension {
    private array $user_feeds;
    private array $selected_feeds = [];
    private string $pushover_user = '';
    private string $pushover_token = '';
    private bool $ignore_read_articles = false;
    private string $statusMessage = '';
    private bool $bad_status = false;

    public function init() {
        $this->registerHook(Minz_HookType::EntryBeforeAdd, [$this, 'handleFeedUpdate']);
    }

    public function getPushoverUser(): string {
        return $this->pushover_user;
    }

    public function getPushoverToken(): string {
        return $this->pushover_token;
    }

    public function getUserFeeds(): array {
        return $this->user_feeds;
    }

    public function getSelectedFeeds(): array {
        return $this->selected_feeds;
    }

    public function getIgnoreReadArticles(): bool {
        return $this->ignore_read_articles;
    }

    private function getFeeds(): array {
        $feedDAO = FreshRSS_Factory::createFeedDao();
        return $feedDAO->listFeeds();
    }

    private function loadConfigValues() {
        $this->user_feeds = $this->getFeeds();
        $this->selected_feeds = array_map('intval', $this->getUserConfigurationValue('aof_feeds') ?? []);
        $this->pushover_user = (string)($this->getUserConfigurationValue('pushover_user') ?? '');
        $this->pushover_token = (string)($this->getUserConfigurationValue('pushover_token') ?? '');
        $this->ignore_read_articles = filter_var(
            $this->getUserConfigurationValue('ignore_read_articles') ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
    }

    private function sendPushoverMessage($user, $token, $title, $url) {
        $data = [
            'token' => $token,
            'user' => $user,
            'title' => $title,
            'message' => $title . "\n" . $url,
            'url' => $url,
            'url_title' => 'View in FreshRSS',
        ];
        $ch = curl_init('https://api.pushover.net/1/messages.json');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }   

    private function sendStatusMessage() {
        $url_array = ['c' => 'extension'];
        if ($this->bad_status) {
            Minz_Request::bad($this->statusMessage, $url_array);
        } else {
            Minz_Request::good($this->statusMessage, $url_array);
        }
    }

    private function sendTestPushoverMessage() {
        if ($this->pushover_user !== '' && $this->pushover_token !== '') {
            $this->sendPushoverMessage($this->pushover_user, $this->pushover_token, 'Test AlertOnFeed', 'This is a test message from AlertOnFeed extension.');
            $this->statusMessage = 'Test Pushover message sent.';
        } else {
            $this->statusMessage = 'Please save your Pushover credentials first.';
            $this->bad_status = true;
        }
        $this->sendStatusMessage();
    }

    public function handleConfigureAction() {
        parent::handleConfigureAction();

        $this->loadConfigValues();

        if (Minz_Request::isPost()) {
            if (Minz_Request::paramString('send_test') === '1') {
                $this->sendTestPushoverMessage();
            } else {
                $form_selected_feeds = array_values(array_filter(Minz_Request::paramArray('feeds'), static fn($value) => is_scalar($value) && (string)$value !== ''));
                
                $pushover_user = Minz_Request::paramString('pushover_user');
                $pushover_token = Minz_Request::paramString('pushover_token');
                $ignore_read_articles = Minz_Request::paramString('ignore_read_articles') === '1';

                $this->setUserConfigurationValue('aof_feeds', $form_selected_feeds);
                $this->setUserConfigurationValue('pushover_user', $pushover_user);
                $this->setUserConfigurationValue('pushover_token', $pushover_token);
                $this->setUserConfigurationValue('ignore_read_articles', $ignore_read_articles);
                $this->statusMessage = 'Settings saved.';
            }

            $this->sendStatusMessage();
        }

        if (!is_array($this->getSelectedFeeds())) {
            $this->selected_feeds = [];
        }
    }

    public function handleFeedUpdate(FreshRSS_Entry $article) {
        Minz_Log::notice('AlertOnFeed: Checking feed update for entry ID ' . $article->id());

        try {
            $this->loadConfigValues();

            if (empty($this->pushover_user) || empty($this->pushover_token)) {
                return;
            }

            if ($this->ignore_read_articles && $article->isRead()) {
                Minz_Log::notice('AlertOnFeed: Skipping read entry ID ' . $article->id());
                return;
            }

            // $article->feedId and $article->title, $article->link are typical properties
            $feedId = $article->feedId();
            if ($feedId !== '' && in_array($feedId, $this->getSelectedFeeds(), true)) {
                $title = $article->title();
                $url = $article->link();
                $this->sendPushoverMessage($this->pushover_user, $this->pushover_token, $title, $url);
            }
        } catch (Exception $e) {
            Minz_Log::error('AlertOnFeed: Error handling feed update - ' . $e->getMessage());
        } finally {
            return $article;
        }     
    }
}