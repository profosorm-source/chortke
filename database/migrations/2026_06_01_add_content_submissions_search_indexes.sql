-- Migration: Add Search Indexes to content_submissions table for H-10 performance and DoS protection
CREATE INDEX idx_content_search ON content_submissions(title, description(255));
CREATE FULLTEXT INDEX ft_content ON content_submissions(title, description);
