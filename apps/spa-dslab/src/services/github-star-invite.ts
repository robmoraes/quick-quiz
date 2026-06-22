const githubStarInviteClickedKey = 'quickquiz.githubStarInviteClicked';

export const githubRepositoryUrl = 'https://github.com/robmoraes/quick-quiz';

export function shouldShowGithubStarInvite() {
  return window.localStorage.getItem(githubStarInviteClickedKey) !== 'true';
}

export function markGithubStarInviteClicked() {
  window.localStorage.setItem(githubStarInviteClickedKey, 'true');
}
