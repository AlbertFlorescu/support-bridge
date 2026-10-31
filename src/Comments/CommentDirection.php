<?php

declare(strict_types=1);

namespace Bridge\Comments;

enum CommentDirection: string
{
    case GitHubToZendesk = 'github_to_zendesk';
    case ZendeskToGitHub = 'zendesk_to_github';

    public function sourceField(): string
    {
        return $this === self::GitHubToZendesk ? 'github_id' : 'zendesk_id';
    }

    public function destinationField(): string
    {
        return $this === self::GitHubToZendesk ? 'zendesk_id' : 'github_id';
    }

    public function table(): string
    {
        return $this === self::GitHubToZendesk ? 'comment_deliveries' : 'note_deliveries';
    }

    public function streamTable(): string
    {
        return $this === self::GitHubToZendesk ? 'comment_streams' : 'note_streams';
    }

    public function destinationColumn(): string
    {
        return $this === self::GitHubToZendesk ? 'zendesk_comment_id' : 'github_comment_id';
    }

    public function markerPrefix(): string
    {
        return $this === self::GitHubToZendesk ? 'Support Bridge comment: ' : 'Support Bridge note: ';
    }

    public function event(string $suffix): string
    {
        return ($this === self::GitHubToZendesk ? 'comment_' : 'note_') . $suffix;
    }

    public function opposite(): self
    {
        return $this === self::GitHubToZendesk ? self::ZendeskToGitHub : self::GitHubToZendesk;
    }
}
