<?php

declare(strict_types=1);

namespace Bridge\Http\Review;

/** Escape source text and render decisions for the operator; the view grants no authority. */
final readonly class Page
{
    public function __construct(
        private Configuration $configuration,
        private ?\Bridge\Application\Configuration $source = null
    ) {
    }

    public function listing(array $pairs, string $login): string
    {
        $rows = '';
        foreach ($pairs as $pair) {
            $rows .= '<tr><td>Zendesk #' . self::escape($pair['zendesk_id']) . '</td><td>GitHub #' .
                self::escape($pair['github_id']) . '</td><td>' . self::escape($pair['status']) .
                '</td><td><a class="button secondary" href="/review/pairs/' . rawurlencode($pair['id']) .
                '">Review details</a></td></tr>';
        }
        return $this->layout(
            'Review pending changes',
            '<p>Signed in through Grafana as <strong>' . self::escape($login) . '</strong>.</p>' .
            '<p>Select an escalation. Only an exact pending state-change plan can be approved.</p>' .
            '<table><thead><tr><th>Support ticket</th><th>Engineering issue</th><th>Workflow</th>' .
            '<th>Action</th></tr></thead><tbody>' . $rows . '</tbody></table>' .
            '<p class="muted">Source changes are observed by the worker. Refresh to load current stored evidence.</p>'
        );
    }

    public function inspection(array $inspection, Session $session, FormProtection $protection): string
    {
        $pair = $inspection['pair'];
        $governance = $inspection['governance'];
        $status = match ($pair['status']) {
            'awaiting_approval' => 'Awaiting approval',
            'synced' => 'Synced',
            'new' => 'Ready for next pass',
            'conflict' => 'Conflict',
            'retry' => 'Retry scheduled',
            'dead' => 'Needs investigation',
            default => 'Unknown stored state',
        };
        $body = '<p><a href="/review">All escalations</a></p><h2>Zendesk #' .
            self::escape($pair['zendesk_id']) . ' / GitHub #' . self::escape($pair['github_id']) . '</h2>' .
            '<p>Workflow: <strong data-testid="workflow-state">' . self::escape($status) . '</strong></p>';
        if ($this->source?->mode === 'live') {
            $zendesk = 'https://' . $this->source->subdomain . '.zendesk.com/agent/tickets/' . $pair['zendesk_id'];
            $github = 'https://github.com/' . $this->source->repository . '/issues/' . $pair['github_id'];
            $body .= '<p><a href="' . self::escape($zendesk) . '" target="_blank" rel="noopener noreferrer">' .
                'Open Zendesk ticket</a> &nbsp; <a href="' . self::escape($github) .
                '" target="_blank" rel="noopener noreferrer">Open GitHub issue</a></p>';
        }
        if ($governance['approved']) {
            $body .= '<p class="notice success">Approval recorded. ' .
                'The worker will apply it after another freshness check.</p>';
        } elseif ($pair['status'] === 'synced') {
            $body .= '<p class="notice success">The supported fields were last verified in agreement. ' .
                'No approval is pending.</p>';
        } elseif ($pair['status'] !== 'awaiting_approval') {
            $body .= '<p class="notice">This pair has no approvable state-change plan. ' .
                'Conflicts and failures need separate investigation.</p>';
        }
        if ($pair['baseline'] !== null) {
            $body .= '<p>Last verified subject: <strong>' . self::escape($pair['baseline']['title']) . '</strong></p>';
        }
        $plan = $pair['plan'];
        if ($plan !== null) {
            $body .= '<h2>Proposed change</h2><ul class="changes">' . $this->changes($pair) . '</ul>' .
                '<table><thead><tr><th>Field</th><th>GitHub observed</th><th>Zendesk observed</th>' .
                '<th>Common target</th></tr></thead><tbody>';
            foreach (['title' => 'Subject / title', 'state' => 'Canonical state'] as $field => $label) {
                $body .= '<tr><th>' . $label . '</th><td>' . self::escape($plan['before']['github'][$field]) .
                    '</td><td>' . self::escape($plan['before']['zendesk'][$field]) . '</td><td>' .
                    self::escape($plan['target'][$field]) . '</td></tr>';
            }
            $body .= '</tbody></table><p class="muted">Zendesk New/Open/Pending/Hold map to open; ' .
                'Solved/Closed map to closed. A closing write uses Solved, never irreversibly Closed.</p>' .
                '<details><summary>Plan identity and observed versions</summary><dl><dt>Policy</dt><dd>' .
                self::escape($governance['policy_version']) . '</dd><dt>Exact plan hash</dt><dd class="hash">' .
                self::escape($governance['plan_hash']) . '</dd><dt>GitHub version</dt><dd>' .
                self::escape($plan['versions']['github']) . '</dd><dt>Zendesk version</dt><dd>' .
                self::escape($plan['versions']['zendesk']) . '</dd></dl></details>';
        }
        if ($pair['status'] === 'awaiting_approval' && $governance['requires_approval'] && !$governance['approved']) {
            $hash = $governance['plan_hash'];
            $proof = $protection->issue($session->current, $pair['id'], $hash);
            $body .= '<form method="post" action="/review/pairs/' . rawurlencode($pair['id']) . '/approve">' .
                '<input type="hidden" name="plan_hash" value="' . self::escape($hash) . '">' .
                '<input type="hidden" name="csrf" value="' . self::escape($proof) . '">' .
                '<label for="reason">Your reason for approving this exact change</label>' .
                '<textarea id="reason" name="reason" required maxlength="500" rows="3"></textarea>' .
                '<p class="muted">Use your own reason, up to 500 UTF-8 bytes. It is retained in audit.</p>' .
                '<label class="confirmation"><input type="checkbox" name="confirm" value="yes" required> ' .
                'I reviewed both linked records and the complete plan above.</label>' .
                '<button type="submit">Approve this exact change</button></form>';
        }
        $body .= '<p><a class="button secondary" href="/review/pairs/' . rawurlencode($pair['id']) .
            '">Refresh current evidence</a></p><p class="muted">Signed in as ' . self::escape($session->login) .
            '. This form expires after ten minutes. Refresh it to review current evidence.</p>';
        return $this->layout('Escalation review', $body);
    }

    public function error(string $message, string $requestId): string
    {
        return $this->layout(
            'Review unavailable',
            '<p class="notice">' . self::escape($message) . '</p><p><a class="button" href="' .
                self::escape($this->configuration->grafanaOrigin . '/login') . '">Sign in to Grafana</a> ' .
                '<a class="button secondary" href="/review">Reload reviews</a></p>' .
                '<p class="muted">After signing in, return to this tab. Reference: ' . self::escape($requestId) . '</p>'
        );
    }

    private function changes(array $pair): string
    {
        $rows = '';
        foreach (['github' => 'GitHub issue', 'zendesk' => 'Zendesk ticket'] as $side => $label) {
            foreach (['title', 'state'] as $field) {
                $before = $pair['plan']['before'][$side][$field];
                $after = $pair['plan']['target'][$field];
                if ($before === $after) {
                    continue;
                }
                $value = $field === 'state' && $side === 'zendesk'
                    ? ($after === 'closed' ? 'Solved' : 'Open') : $after;
                $rows .= '<li>Set ' . $label . ' #' . self::escape($pair[$side . '_id']) . ' ' .
                    ($field === 'title' ? 'subject / title' : 'state') . ' to <strong>' .
                    self::escape($value) . '</strong>.</li>';
            }
        }
        return $rows;
    }

    private function layout(string $title, string $body): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">' .
            '<meta name="viewport" content="width=device-width,initial-scale=1"><title>' .
            self::escape($title) . ' | Support Bridge</title><link rel="stylesheet" href="/assets/review.css">' .
            '</head><body><main><header><p class="brand">SUPPORT BRIDGE</p><h1>' . self::escape($title) .
            '</h1><a href="' . self::escape($this->configuration->grafanaOrigin .
                '/d/supportbridge-overview?refresh=5s') . '">Back to Grafana</a></header>' . $body .
            '<footer>Approval covers only the change shown. The worker checks current records ' .
            'before applying it.</footer></main></body></html>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
