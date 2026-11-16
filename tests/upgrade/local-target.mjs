export const localTarget = Object.freeze({
  id: 'local',
  name: 'Local test clone',
  url: 'http://127.0.0.1:18411',
});

export function selectTarget(id) {
  if (id && id !== localTarget.id) {
    throw new Error('The test lab only supports its isolated local environment.');
  }
  return localTarget;
}
