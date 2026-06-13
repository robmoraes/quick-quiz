import MarkdownIt from 'markdown-it';

const markdown = new MarkdownIt({
  html: false,
  breaks: true,
  linkify: false,
  typographer: false,
});

markdown.disable([
  'autolink',
  'blockquote',
  'heading',
  'hr',
  'html_block',
  'html_inline',
  'image',
  'lheading',
  'link',
  'reference',
  'table',
]);

export function renderQuizMarkdown(source: string): string {
  return markdown.render(source);
}
