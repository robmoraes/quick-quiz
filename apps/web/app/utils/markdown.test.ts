import { describe, expect, it } from 'vitest'
import { renderQuizMarkdown } from './markdown'
describe('quiz Markdown boundary', () => {
  it('renders code without enabling author-provided HTML or executable URLs', () => {
    const output = renderQuizMarkdown('<img src=x onerror=alert(1)>\n\n[bad](javascript:alert(1))\n\n```php\n<?php echo "Quiz";\n```')
    expect(output).not.toContain('<img')
    expect(output).not.toContain('href="javascript:')
    expect(output).toContain('<code class="language-php">')
    expect(output).toContain('&lt;?php')
  })

  it.each([
    ['.', '.'], [',', ','], ['+', '+'], ['-', '-'], ['*', '*'], ['---', '---'],
    ['<', '&lt;'], ['>', '&gt;'], ['&&', '&amp;&amp;'], ['!=', '!='],
  ])('keeps standalone %s visible as a literal symbol', (input, escaped) => {
    expect(renderQuizMarkdown(input)).toBe(`<p class="quiz-symbol"><code>${escaped}</code></p>\n`)
  })

  it('gives a standalone operator the same presentation with or without inline code markup', () => {
    expect(renderQuizMarkdown('`.`')).toBe('<p class="quiz-symbol"><code>.</code></p>\n')
    expect(renderQuizMarkdown('`+`')).toBe('<p class="quiz-symbol"><code>+</code></p>\n')
  })

  it('preserves Markdown structure and inline code within ordinary quiz text', () => {
    expect(renderQuizMarkdown('Use `.` para concatenar.')).toBe('<p>Use <code>.</code> para concatenar.</p>\n')
    expect(renderQuizMarkdown('- First\n- Second')).toContain('<li>First</li>')
    expect(renderQuizMarkdown('---\n\nText')).toBe('<hr>\n<p>Text</p>\n')
    expect(renderQuizMarkdown('```php\n.\n```')).toBe('<pre><code class="language-php">.\n</code></pre>\n')
  })
})
