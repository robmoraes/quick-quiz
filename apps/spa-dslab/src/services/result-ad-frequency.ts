let resultViewCount = 0;

export function nextResultViewCount(): number {
  resultViewCount += 1;
  return resultViewCount;
}

export function shouldShowResultInterstitial(count: number): boolean {
  return isPrime(count);
}

export function resetResultViewCount() {
  resultViewCount = 0;
}

function isPrime(value: number): boolean {
  if (value < 2) {
    return false;
  }

  for (let divisor = 2; divisor * divisor <= value; divisor += 1) {
    if (value % divisor === 0) {
      return false;
    }
  }

  return true;
}
