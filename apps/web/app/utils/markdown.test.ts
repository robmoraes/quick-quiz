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
})
