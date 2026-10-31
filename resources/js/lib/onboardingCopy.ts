// Copy of the onboarding steps and their "done" view on the Portfolio: lang/en/onboarding.php, sent by
// OnboardingController (and PortfolioController's `welcome` prop) as `copy`. Fill :placeholders with t() / fillPlaceholders.

export type OnboardingCopy = {
  title: string;
  overline: string;
  heading: string;
  subline: string;
  stepper: { label: string; steps: [string, string, string]; current: string; done: string };
  project: Record<
    'title' | 'sub' | 'name' | 'name_placeholder' | 'description' | 'description_placeholder' | 'description_help' | 'lead' | 'lead_you'
    | 'lead_help' | 'address' | 'address_change' | 'address_hide' | 'slug' | 'slug_help' | 'prefix' | 'prefix_help' | 'submit' | 'busy'
    | 'created' | 'created_body' | 'continue' | 'off' | 'skip_to_bot', string>;
  board: Record<'title' | 'sub' | 'list_label' | 'rename_label' | 'core' | 'help' | 'no_project' | 'back' | 'save' | 'busy' | 'continue', string>;
  bot: {
    tag: string; title: string; sub: string; steps: { title: string; body: string }[]; your_projects: string; verify: string;
    skip: string; create_token: string; later: string;
  };
  done: Record<'ready' | 'connect' | 'connect_link', string>;
  errors: Record<
    'name_required' | 'slug_required' | 'slug_regex' | 'slug_taken' | 'prefix_required' | 'prefix_regex' | 'prefix_taken' | 'column_required'
    | 'column_max' | 'generic', string>;
};
