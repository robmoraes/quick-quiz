import MarkdownIt from 'markdown-it'

const markdown = new MarkdownIt({ html: false, linkify: false, breaks: true })
const symbolOnly = /^[^\p{L}\p{N}\s`]+$/u

function standaloneSymbol(content: string): string | undefined {
  const trimmed = content.trim()
  if (symbolOnly.test(trimmed)) return trimmed
  if (!trimmed.startsWith('`')) return undefined

  const blocks = markdown.parse(trimmed, {})
  const children = blocks[1]?.children
  if (blocks.length === 3 && blocks[0]?.type === 'paragraph_open'
    && blocks[2]?.type === 'paragraph_close' && children?.length === 1
    && children[0]?.type === 'code_inline' && symbolOnly.test(children[0].content)) {
    return children[0].content
  }
  return undefined
}

export function renderQuizMarkdown(content: string): string {
  const symbol = standaloneSymbol(content)
  // Standalone operators such as +, * and > must not become Markdown structure.
  if (symbol !== undefined) {
    return `<p class="quiz-symbol"><code>${markdown.utils.escapeHtml(symbol)}</code></p>\n`
  }
  return markdown.render(content)
}
