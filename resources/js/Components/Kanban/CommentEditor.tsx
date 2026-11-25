import { useEffect, useRef } from 'react';
import type { ReactNode } from 'react';
import { EditorContent, useEditor, useEditorState } from '@tiptap/react';
import type { Editor } from '@tiptap/react';
import Bold from '@tiptap/extension-bold';
import Code from '@tiptap/extension-code';
import Document from '@tiptap/extension-document';
import HardBreak from '@tiptap/extension-hard-break';
import Italic from '@tiptap/extension-italic';
import Link from '@tiptap/extension-link';
import { BulletList, ListItem, ListKeymap, OrderedList } from '@tiptap/extension-list';
import Paragraph from '@tiptap/extension-paragraph';
import Text from '@tiptap/extension-text';
import { Placeholder, UndoRedo } from '@tiptap/extensions';
import { linkHref, toMarkdown } from '../../lib/commentMarkdown';
import { t as tr } from '../../lib/strings';

type Props = {
  id: string;
  labelId: string;
  placeholder: string;
  /** Bumped by the parent to empty the editor (after a send). */
  resetKey: number;
  onChange: (markdown: string) => void;
  onSubmit: () => void;
};

/**
 * The ticket comment box: a small rich text editor (Tiptap) for bold, italic, inline code, links and lists. It reports
 * Markdown (lib/commentMarkdown.ts), which is what the comment API stores; Cmd/Ctrl+Enter submits.
 */
export function CommentEditor({ id, labelId, placeholder, resetKey, onChange, onSubmit }: Props) {
  const latest = useRef({ onChange, onSubmit });
  latest.current = { onChange, onSubmit };
  const editor = useEditor({
    extensions: [
      Document, Paragraph, Text, HardBreak, Bold, Italic, Code, BulletList, OrderedList, ListItem, ListKeymap, UndoRedo,
      Link.configure({
        openOnClick: false,
        defaultProtocol: 'https',
        HTMLAttributes: { rel: 'noopener noreferrer', target: null },
        isAllowedUri: (url) => linkHref(url) !== null,
      }),
      Placeholder.configure({ placeholder }),
    ],
    editorProps: {
      attributes: { id, class: 'qta', role: 'textbox', 'aria-multiline': 'true', 'aria-labelledby': labelId },
      handleKeyDown: (_view, e) => {
        if (e.key !== 'Enter' || !(e.metaKey || e.ctrlKey)) return false;
        e.preventDefault();
        latest.current.onSubmit();
        return true;
      },
    },
    onUpdate: ({ editor: ed }) => latest.current.onChange(toMarkdown(ed.getJSON())),
  });
  useEffect(() => { if (resetKey) editor?.commands.clearContent(true); }, [editor, resetKey]);

  return (
    <div className="rte">
      {editor && <Toolbar editor={editor} controls={id} />}
      <EditorContent editor={editor} />
    </div>
  );
}

function Toolbar({ editor, controls }: { editor: Editor; controls: string }) {
  const active = useEditorState({
    editor,
    selector: ({ editor: ed }) => ({
      bold: ed.isActive('bold'), italic: ed.isActive('italic'), code: ed.isActive('code'), link: ed.isActive('link'),
      bulletList: ed.isActive('bulletList'), orderedList: ed.isActive('orderedList'),
    }),
  });
  const chain = () => editor.chain().focus();
  const link = () => {
    const input = window.prompt(tr('ticket.composer.linkPrompt'), String(editor.getAttributes('link').href ?? ''));
    if (input === null) return;
    const href = linkHref(input);
    if (!input.trim()) chain().extendMarkRange('link').unsetLink().run();
    else if (href) chain().extendMarkRange('link').setLink({ href }).run();
  };
  const button = (name: keyof typeof active, label: string, run: () => void, glyph: ReactNode) => (
    <button type="button" aria-label={label} title={label} aria-pressed={active[name]} aria-controls={controls}
      onMouseDown={(e) => e.preventDefault()} onClick={run}>{glyph}</button>
  );
  return (
    <div className="rte-bar" role="toolbar" aria-label={tr('ticket.composer.toolbar')}>
      {button('bold', tr('ticket.composer.bold'), () => chain().toggleBold().run(), <b>B</b>)}
      {button('italic', tr('ticket.composer.italic'), () => chain().toggleItalic().run(), <i>I</i>)}
      {button('code', tr('ticket.composer.code'), () => chain().toggleCode().run(), <code>{'</>'}</code>)}
      {button('link', tr('ticket.composer.link'), link, <IcLink />)}
      {button('bulletList', tr('ticket.composer.bullets'), () => chain().toggleBulletList().run(), <IcBullets />)}
      {button('orderedList', tr('ticket.composer.numbers'), () => chain().toggleOrderedList().run(), <IcNumbers />)}
    </div>
  );
}

const IcLink = () => <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M6.5 9.5a3 3 0 0 0 4.2 0l2-2a3 3 0 0 0-4.2-4.2l-.8.8M9.5 6.5a3 3 0 0 0-4.2 0l-2 2a3 3 0 0 0 4.2 4.2l.8-.8" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" /></svg>;
const IcBullets = () => <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="3" cy="4" r="1" fill="currentColor" /><circle cx="3" cy="8" r="1" fill="currentColor" /><circle cx="3" cy="12" r="1" fill="currentColor" /><path d="M6.5 4H14M6.5 8H14M6.5 12H14" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" /></svg>;
const IcNumbers = () => <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M2.5 3h1v3M2.5 6h2M2.5 10h2l-2 2.5h2M6.5 4.5H14M6.5 11H14" stroke="currentColor" strokeWidth="1.3" strokeLinecap="round" strokeLinejoin="round" /></svg>;
