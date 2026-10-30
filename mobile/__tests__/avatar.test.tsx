import React from 'react';
import { Image, StyleSheet, Text, View } from 'react-native';
import { act, create, ReactTestRenderer } from 'react-test-renderer';
import { Avatar } from '../src/components/Avatar';
import { palette } from '../src/theme/tokens';

const firstPhoto = 'data:image/png;base64,Zmlyc3Q=';
const nextPhoto = 'data:image/jpeg;base64,bmV4dA==';
let renderer: ReactTestRenderer | undefined;

function render(props: React.ComponentProps<typeof Avatar>) {
  act(() => {
    if (renderer) {
      renderer.update(<Avatar {...props} />);
    } else {
      renderer = create(<Avatar {...props} />);
    }
  });
}

function expectFallback(initials = 'AR') {
  expect(renderer!.root.findAllByType(Image)).toHaveLength(0);
  const label = renderer!.root.findByType(Text);
  expect(label.props.children).toBe(initials);
  expect(StyleSheet.flatten(label.props.style)).toMatchObject({ color: '#ffffff' });
  expect(StyleSheet.flatten(renderer!.root.findByType(View).props.style)).toMatchObject({
    backgroundColor: palette.brand700,
  });
}

afterEach(() => {
  act(() => renderer?.unmount());
  renderer = undefined;
});

describe('Avatar', () => {
  it.each([
    { size: 'small' as const, diameter: 38, fontSize: 11, borderWidth: 2 },
    { size: 'large' as const, diameter: 86, fontSize: 22, borderWidth: 3 },
  ])('renders white initials on a maroon $size fallback', ({ size, diameter, fontSize, borderWidth }) => {
    render({ initials: 'AR', size, photoUri: null });

    expectFallback();
    expect(StyleSheet.flatten(renderer!.root.findByType(View).props.style)).toMatchObject({
      width: diameter, height: diameter, borderRadius: 999, overflow: 'hidden',
      borderWidth, borderColor: palette.brand700,
    });
    expect(StyleSheet.flatten(renderer!.root.findByType(Text).props.style)).toMatchObject({ fontSize });
  });

  it('defaults to the small fallback without requiring a URI', () => {
    render({ initials: 'JT' });

    expectFallback('JT');
    expect(StyleSheet.flatten(renderer!.root.findByType(View).props.style)).toMatchObject({
      width: 38, height: 38,
    });
  });

  it.each(['small', 'large'] as const)('fills the %s avatar with a cover image, without initials or auth headers', size => {
    render({ initials: 'AR', size, photoUri: firstPhoto });

    const image = renderer!.root.findByType(Image);
    expect(image.props.source).toEqual({ uri: firstPhoto });
    expect(image.props.resizeMode).toBe('cover');
    expect(image.props.accessibilityLabel).toBe('Your profile photo');
    expect(StyleSheet.flatten(image.props.style)).toMatchObject({ width: '100%', height: '100%' });
    expect(renderer!.root.findAllByType(Text)).toHaveLength(0);
    expect(StyleSheet.flatten(renderer!.root.findByType(View).props.style)).toMatchObject({
      borderColor: palette.brand700, borderWidth: size === 'large' ? 3 : 2,
    });
  });

  it('falls back on image error, keeps the failed URI hidden, and tries a different image', () => {
    render({ initials: 'AR', photoUri: firstPhoto });
    act(() => renderer!.root.findByType(Image).props.onError({ nativeEvent: { error: 'decode failed' } }));
    expectFallback();

    render({ initials: 'JT', photoUri: firstPhoto });
    expectFallback('JT');

    render({ initials: 'JT', photoUri: nextPhoto });
    expect(renderer!.root.findByType(Image).props.source).toEqual({ uri: nextPhoto });
    expect(renderer!.root.findAllByType(Text)).toHaveLength(0);

    act(() => renderer!.root.findByType(Image).props.onError({ nativeEvent: { error: 'decode failed' } }));
    expectFallback('JT');
  });

  it.each([null, undefined, ''])('restores initials when the photo is removed (%s)', photoUri => {
    render({ initials: 'AR', photoUri: firstPhoto });
    expect(renderer!.root.findAllByType(Image)).toHaveLength(1);

    render({ initials: 'AR', photoUri });
    expectFallback();

    render({ initials: 'AR', photoUri: nextPhoto });
    expect(renderer!.root.findByType(Image).props.source).toEqual({ uri: nextPhoto });
  });
});
