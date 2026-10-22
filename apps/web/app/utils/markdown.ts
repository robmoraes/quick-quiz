import MarkdownIt from 'markdown-it'
const markdown = new MarkdownIt({ html: false, linkify: false, breaks: true })
export function renderQuizMarkdown(content: string): string { return markdown.render(content) }
